<?php

declare(strict_types=1);

namespace FenPing\Topology;

use FenPing\Database\DatabaseManager;
use PDO;

final readonly class TopologyRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /** @return list<array{scan_id: int, target_ip: string, mode: string, observed_at: string, hops: list<array{position: int, protocol: string, port: ?int, ttl: int, ip: string, hostname: string, rtt: ?float}>}> */
    public function latestTracePaths(): array
    {
        $statement = $this->database->connection()->query(<<<'SQL'
            WITH trace_snapshots AS (
              SELECT DISTINCT snapshot_id
              FROM scan_snapshot_trace_hops
            ), latest AS (
              SELECT scans.ip, MAX(scans.id) AS id
              FROM scans
              INNER JOIN trace_snapshots ON trace_snapshots.snapshot_id=scans.snapshot_id
              WHERE scans.state='complete'
                AND scans.snapshot_id IS NOT NULL
              GROUP BY scans.ip
            )
            SELECT
              scans.id AS scan_id,
              scans.ip AS target_ip,
              scans.mode,
              COALESCE(NULLIF(scans.date_end, ''), scans.date_begin, '') AS observed_at,
              hops.position,
              hops.protocol,
              hops.port,
              hops.ttl,
              hops.ip,
              hops.hostname,
              hops.rtt
            FROM latest
            INNER JOIN scans ON scans.id=latest.id
            INNER JOIN scan_snapshot_trace_hops hops ON hops.snapshot_id=scans.snapshot_id
            ORDER BY scans.ip, hops.position, hops.id
            SQL);

        $paths = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $scanId = (int) $row['scan_id'];
            if (!isset($paths[$scanId])) {
                $paths[$scanId] = [
                    'scan_id' => $scanId,
                    'target_ip' => (string) $row['target_ip'],
                    'mode' => (string) $row['mode'],
                    'observed_at' => (string) $row['observed_at'],
                    'hops' => [],
                ];
            }
            $paths[$scanId]['hops'][] = [
                'position' => (int) $row['position'],
                'protocol' => (string) ($row['protocol'] ?? ''),
                'port' => $row['port'] === null ? null : (int) $row['port'],
                'ttl' => (int) $row['ttl'],
                'ip' => (string) $row['ip'],
                'hostname' => (string) ($row['hostname'] ?? ''),
                'rtt' => $row['rtt'] === null ? null : (float) $row['rtt'],
            ];
        }
        return array_values($paths);
    }

    /** @return list<array{host_id: int, host_ip: string, host_name: string, router_octet: string}> */
    public function gatewayAssignments(): array
    {
        $statement = $this->database->connection()->query(<<<'SQL'
            SELECT
              id,
              COALESCE(ip, '') AS ip,
              COALESCE(NULLIF(display_name, ''), NULLIF(name, ''), ip, '') AS host_name,
              CAST(router AS TEXT) AS router_octet
            FROM ips
            WHERE router IS NOT NULL
              AND CAST(router AS TEXT)<>''
            ORDER BY id
            SQL);
        $assignments = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $assignments[] = [
                'host_id' => (int) $row['id'],
                'host_ip' => (string) $row['ip'],
                'host_name' => (string) $row['host_name'],
                'router_octet' => (string) $row['router_octet'],
            ];
        }
        return $assignments;
    }
}
