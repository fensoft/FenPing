<?php

declare(strict_types=1);

namespace FenPing\Report;

use DateTimeImmutable;
use DateTimeZone;
use FenPing\Database\DatabaseManager;
use PDO;
use Throwable;

final readonly class ScheduledReportQueryRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function summary(DateTimeImmutable $start, DateTimeImmutable $end, int $certificateDays): array
    {
        $parameters = [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
        $outages = $this->fetch(
            "SELECT s.ip, s.mac, s.status, s.date_begin,
                    COALESCE((SELECT NULLIF(i.display_name, '') FROM ips i
                              WHERE i.ip=s.ip OR (s.mac <> '' AND i.mac=s.mac) LIMIT 1),
                             (SELECT NULLIF(i.name, '') FROM ips i
                              WHERE i.ip=s.ip OR (s.mac <> '' AND i.mac=s.mac) LIMIT 1), s.ip) AS name
             FROM stats s
             WHERE s.date_begin >= :start AND s.date_begin < :end
               AND LOWER(COALESCE(s.status, '')) <> 'up'
               AND EXISTS (SELECT 1 FROM stats previous
                           WHERE previous.ip=s.ip AND previous.id < s.id
                             AND LOWER(COALESCE(previous.status, ''))='up')
             ORDER BY s.date_begin",
            $parameters,
        );
        $newDevices = $this->fetch(
            "SELECT s.ip, s.mac, s.status, s.date_begin,
                    COALESCE((SELECT NULLIF(i.display_name, '') FROM ips i
                              WHERE i.ip=s.ip OR (s.mac <> '' AND i.mac=s.mac) LIMIT 1),
                             (SELECT NULLIF(i.name, '') FROM ips i
                              WHERE i.ip=s.ip OR (s.mac <> '' AND i.mac=s.mac) LIMIT 1), s.ip) AS name
             FROM stats s
             WHERE s.date_begin >= :start AND s.date_begin < :end
               AND NOT EXISTS (
                 SELECT 1 FROM stats previous WHERE previous.id < s.id AND
                 ((COALESCE(s.mac, '') <> '' AND previous.mac=s.mac) OR
                  (COALESCE(s.mac, '') = '' AND previous.ip=s.ip))
               )
             ORDER BY s.date_begin",
            $parameters,
        );
        $conflicts = $this->fetch(
            "SELECT id, ip, network, detected_at AS occurred_at, 'detected' AS event,
                    (SELECT COUNT(*) FROM ip_conflict_devices d WHERE d.conflict_id=ip_conflicts.id) AS device_count
             FROM ip_conflicts WHERE detected_at >= :start AND detected_at < :end
             UNION ALL
             SELECT id, ip, network, resolved_at AS occurred_at, 'resolved' AS event,
                    (SELECT COUNT(*) FROM ip_conflict_devices d WHERE d.conflict_id=ip_conflicts.id) AS device_count
             FROM ip_conflicts WHERE resolved_at >= :start AND resolved_at < :end
             ORDER BY occurred_at",
            $parameters,
        );
        $ports = $this->fetch(
            "SELECT ip, change_type, protocol, port, previous_service, previous_version,
                    current_service, current_version, created_at
             FROM scan_port_changes
             WHERE created_at >= :start AND created_at < :end
             ORDER BY created_at",
            $parameters,
        );
        $anomalies = $this->fetch(
            "SELECT network, anomaly_type, subtype, event_type, ip, previous_ip, mac,
                    hostname, vendor, important, details_json, occurred_at
             FROM network_anomaly_events
             WHERE occurred_at >= :start AND occurred_at < :end
             ORDER BY occurred_at",
            $parameters,
        );
        foreach ($anomalies as &$anomaly) {
            $anomaly['important'] = (int) $anomaly['important'];
            $anomaly['details'] = json_decode((string) $anomaly['details_json'], true) ?: [];
            unset($anomaly['details_json']);
        }
        unset($anomaly);
        $certificates = $this->expiringCertificates($end, $certificateDays);
        return [
            'window_start' => $parameters['start'],
            'window_end' => $parameters['end'],
            'counts' => [
                'outages' => count($outages),
                'new_devices' => count($newDevices),
                'conflicts' => count($conflicts),
                'changed_ports' => count($ports),
                'anomalies' => count($anomalies) + count(array_filter($ports, static fn(array $row): bool => $row['change_type'] === 'appeared')),
                'expiring_certificates' => count($certificates),
            ],
            'outages' => $outages,
            'new_devices' => $newDevices,
            'conflicts' => $conflicts,
            'changed_ports' => $ports,
            'anomalies' => $anomalies,
            'expiring_certificates' => $certificates,
        ];
    }

    private function expiringCertificates(DateTimeImmutable $at, int $days): array
    {
        $rows = $this->fetch(
            "SELECT snapshots.ip, ports.protocol, ports.port, scripts.output,
                    GROUP_CONCAT(COALESCE(nodes.node_key, '') || '=' || COALESCE(nodes.value, ''), char(10)) AS node_values,
                    MAX(scans.date_end) AS observed_at
             FROM scan_snapshot_scripts scripts
             JOIN scan_snapshots snapshots ON snapshots.id=scripts.snapshot_id
             JOIN scan_snapshot_ports ports ON ports.id=scripts.port_id
             JOIN scans ON scans.snapshot_id=snapshots.id AND scans.state='complete'
             LEFT JOIN scan_snapshot_script_nodes nodes ON nodes.script_id=scripts.id
             WHERE LOWER(scripts.script_id)='ssl-cert'
             GROUP BY snapshots.ip, ports.protocol, ports.port, scripts.id
             ORDER BY observed_at DESC",
        );
        $seen = [];
        $result = [];
        $utc = new DateTimeZone('UTC');
        $deadline = $at->modify('+' . $days . ' days');
        foreach ($rows as $row) {
            $key = $row['ip'] . '|' . $row['protocol'] . '|' . $row['port'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $expires = $this->certificateExpiry((string) $row['output'] . "\n" . (string) $row['node_values'], $utc);
            if ($expires === null || $expires > $deadline) {
                continue;
            }
            $row['expires_at'] = $expires->format('Y-m-d H:i:s');
            $row['days_remaining'] = (int) ceil(($expires->getTimestamp() - $at->getTimestamp()) / 86400);
            unset($row['output'], $row['node_values']);
            $result[] = $row;
        }
        usort($result, static fn(array $a, array $b): int => strcmp($a['expires_at'], $b['expires_at']));
        return $result;
    }

    private function certificateExpiry(string $text, DateTimeZone $utc): ?DateTimeImmutable
    {
        $patterns = [
            '/Not valid after:\s*([^\r\n]+)/i',
            '/(?:^|[\r\n])\s*notAfter\s*[=:]\s*([^\r\n]+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }
            $value = trim($match[1]);
            try {
                return new DateTimeImmutable($value, $utc);
            } catch (Throwable) {
                continue;
            }
        }
        return null;
    }

    private function fetch(string $sql, array $parameters = []): array
    {
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
