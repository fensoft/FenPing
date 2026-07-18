<?php

declare(strict_types=1);

namespace FenPing\Export;

use DateTimeImmutable;
use DateTimeZone;
use FenPing\Api\Response;
use FenPing\Database\DatabaseManager;
use FenPing\Inventory\InventoryService;
use FenPing\Network\Ipv4Network;
use FenPing\Scan\ResultService;
use InvalidArgumentException;
use PDO;

final readonly class InventoryExportService
{
    private const DATASETS = ['hosts', 'leases', 'services', 'scan_changes', 'uptime_history'];

    public function __construct(
        private DatabaseManager $database,
        private InventoryService $inventory,
        private ResultService $scanResults,
    ) {}

    public function download(string $dataset, string $format, Ipv4Network $network): Response
    {
        if (!in_array($dataset, self::DATASETS, true)) {
            throw new InvalidArgumentException('invalid export dataset');
        }
        if (!in_array($format, ['csv', 'json'], true)) {
            throw new InvalidArgumentException('invalid export format');
        }
        $export = $this->dataset($dataset, $network);
        $generated = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $body = $format === 'csv'
            ? $this->csv($export['columns'], $export['records'])
            : $this->json($dataset, $network->cidr, $generated, $export['records']);
        $filename = sprintf(
            'fenping-%s-%s-%s.%s',
            str_replace('_', '-', $dataset),
            str_replace(['.', '/'], ['-', '-'], $network->cidr),
            $generated->format('Ymd-His'),
            $format,
        );
        return new Response(200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $body);
    }

    public function dataset(string $dataset, Ipv4Network $network): array
    {
        return match ($dataset) {
            'hosts' => $this->hosts($network),
            'leases' => $this->leases($network),
            'services' => $this->services($network),
            'scan_changes' => $this->scanChanges($network),
            'uptime_history' => $this->uptimeHistory($network),
            default => throw new InvalidArgumentException('invalid export dataset'),
        };
    }

    private function hosts(Ipv4Network $network): array
    {
        $columns = [
            'id', 'name', 'display_name', 'ip', 'mac', 'detected_mac', 'vendor', 'status', 'last_seen',
            'important', 'approved', 'is_new', 'dhcp_managed', 'category', 'tags', 'notes', 'location',
            'owner', 'model', 'scan_profile', 'scan_interval_hours', 'uptime_percent', 'service_count', 'last_scan_at',
        ];
        $records = [];
        foreach ($this->inventory->forNetwork($network->cidr) as $host) {
            $scan = is_array($host['scan'] ?? null) ? $host['scan'] : [];
            $stability = is_array($host['stability'] ?? null) ? $host['stability'] : [];
            $records[] = [
                'id' => $host['id'] ?? null,
                'name' => (string) ($host['name'] ?? ''),
                'display_name' => (string) ($host['display_name'] ?? ''),
                'ip' => (string) ($host['ip'] ?? ''),
                'mac' => (string) ($host['mac'] ?? ''),
                'detected_mac' => (string) ($host['detected_mac'] ?? ''),
                'vendor' => (string) ($host['vendor'] ?? ''),
                'status' => (string) ($host['status'] ?? ''),
                'last_seen' => $host['date'] ?? null,
                'important' => (int) ($host['important'] ?? 0),
                'approved' => (int) ($host['approved'] ?? 0),
                'is_new' => (int) ($host['is_new'] ?? 0),
                'dhcp_managed' => (int) ($host['dhcp_managed'] ?? 0),
                'category' => (string) ($host['category'] ?? ''),
                'tags' => implode('; ', is_array($host['tags'] ?? null) ? $host['tags'] : []),
                'notes' => (string) ($host['notes'] ?? ''),
                'location' => (string) ($host['location'] ?? ''),
                'owner' => (string) ($host['owner'] ?? ''),
                'model' => (string) ($host['model'] ?? ''),
                'scan_profile' => (string) ($host['scan_profile'] ?? ''),
                'scan_interval_hours' => (int) ($host['scan_interval_hours'] ?? 0),
                'uptime_percent' => $stability['uptime_percent'] ?? null,
                'service_count' => (int) ($scan['effective_ports_count'] ?? $scan['ports_count'] ?? 0),
                'last_scan_at' => $scan['date_end'] ?? $scan['date_begin'] ?? null,
            ];
        }
        return compact('columns', 'records');
    }

    private function leases(Ipv4Network $network): array
    {
        $columns = ['ip', 'mac', 'hostname', 'ends', 'first_seen', 'last_seen', 'active'];
        $records = $this->fetch(
            'SELECT ip, `hardware-ethernet` AS mac, `client-hostname` AS hostname,
                    ends, first_seen, last_seen, active
             FROM leases ORDER BY ipv4_num(ip), LOWER(`hardware-ethernet`), last_seen',
        );
        $records = array_values(array_filter($records, static fn(array $row): bool => $network->contains((string) $row['ip'])));
        foreach ($records as &$record) $record['active'] = (int) $record['active'];
        unset($record);
        return compact('columns', 'records');
    }

    private function services(Ipv4Network $network): array
    {
        $columns = ['host_id', 'name', 'ip', 'mac', 'vendor', 'protocol', 'port', 'service', 'version', 'tunnel', 'source', 'scan_id', 'scan_mode', 'scan_date', 'merged'];
        $records = array_values(array_filter(
            $this->scanResults->services()['services'],
            static fn(array $row): bool => $network->contains((string) $row['ip']),
        ));
        foreach ($records as &$record) $record['merged'] = !empty($record['merged']) ? 1 : 0;
        unset($record);
        return compact('columns', 'records');
    }

    private function scanChanges(Ipv4Network $network): array
    {
        $columns = ['id', 'scan_id', 'ip', 'mode', 'change_type', 'protocol', 'port', 'previous_service', 'previous_version', 'current_service', 'current_version', 'created_at'];
        $records = $this->fetch(
            'SELECT id, scan_id, ip, mode, change_type, protocol, port,
                    previous_service, previous_version, current_service, current_version, created_at
             FROM scan_port_changes ORDER BY created_at, id',
        );
        $records = array_values(array_filter($records, static fn(array $row): bool => $network->contains((string) $row['ip'])));
        return compact('columns', 'records');
    }

    private function uptimeHistory(Ipv4Network $network): array
    {
        $columns = ['id', 'ip', 'mac', 'status', 'date_begin', 'date_end', 'duration_seconds', 'scan_count', 'current'];
        $records = $this->fetch(
            "SELECT s.id, s.ip, s.mac, s.status,
                    CASE WHEN s.date_begin < datetime('now', '-7 days') THEN datetime('now', '-7 days') ELSE s.date_begin END AS date_begin,
                    CASE WHEN s.id=(SELECT MAX(current.id) FROM stats current WHERE current.ip=s.ip)
                         THEN CURRENT_TIMESTAMP ELSE s.date_end END AS date_end,
                    MAX(0, unixepoch(CASE WHEN s.id=(SELECT MAX(current.id) FROM stats current WHERE current.ip=s.ip)
                                         THEN CURRENT_TIMESTAMP ELSE s.date_end END)
                           - unixepoch(CASE WHEN s.date_begin < datetime('now', '-7 days')
                                            THEN datetime('now', '-7 days') ELSE s.date_begin END)) AS duration_seconds,
                    s.nb_scan AS scan_count,
                    CASE WHEN s.id=(SELECT MAX(current.id) FROM stats current WHERE current.ip=s.ip) THEN 1 ELSE 0 END AS current
             FROM stats s WHERE s.date_end > datetime('now', '-7 days') ORDER BY ipv4_num(s.ip), s.id",
        );
        $records = array_values(array_filter($records, static fn(array $row): bool => $network->contains((string) $row['ip'])));
        foreach ($records as &$record) {
            $record['duration_seconds'] = (int) $record['duration_seconds'];
            $record['scan_count'] = (int) $record['scan_count'];
            $record['current'] = (int) $record['current'];
        }
        unset($record);
        return compact('columns', 'records');
    }

    private function csv(array $columns, array $records): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new \RuntimeException('failed to create export');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $columns, ',', '"', '');
        foreach ($records as $record) {
            fputcsv($stream, array_map($this->csvCell(...), array_map(
                static fn(string $column): mixed => $record[$column] ?? null,
                $columns,
            )), ',', '"', '');
        }
        rewind($stream);
        $body = stream_get_contents($stream);
        fclose($stream);
        if ($body === false) throw new \RuntimeException('failed to read export');
        return $body;
    }

    private function csvCell(mixed $value): string|int|float
    {
        if ($value === null) return '';
        if (is_int($value) || is_float($value)) return $value;
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        return preg_match('/^[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }

    private function json(string $dataset, string $network, DateTimeImmutable $generated, array $records): string
    {
        return (string) json_encode([
            'format' => 'fenping-inventory-export',
            'version' => 1,
            'dataset' => $dataset,
            'network' => $network,
            'exported_at' => $generated->format(DATE_ATOM),
            'count' => count($records),
            'records' => $records,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function fetch(string $sql): array
    {
        return $this->database->connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
