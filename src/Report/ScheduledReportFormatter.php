<?php

declare(strict_types=1);

namespace FenPing\Report;

final readonly class ScheduledReportFormatter
{
    private const DETAIL_LIMIT = 8;

    public function discord(array $report, string $frequency): array
    {
        $fields = [];
        foreach ($this->sections($report) as $section) {
            $fields[] = [
                'name' => $section['title'] . ' · ' . count($section['rows']),
                'value' => $this->trim(implode("\n", array_slice($section['rows'], 0, self::DETAIL_LIMIT)) ?: 'None', 1024),
                'inline' => false,
            ];
        }
        return [
            'username' => 'FenPing',
            'embeds' => [[
                'title' => 'FenPing ' . ucfirst($frequency) . ' Report',
                'description' => $report['window_start'] . ' UTC → ' . $report['window_end'] . ' UTC',
                'color' => 3368652,
                'fields' => $fields,
            ]],
        ];
    }

    public function telegram(array $report, string $frequency): string
    {
        $lines = [
            'FenPing ' . ucfirst($frequency) . ' Report',
            $report['window_start'] . ' UTC → ' . $report['window_end'] . ' UTC',
        ];
        foreach ($this->sections($report) as $section) {
            $lines[] = '';
            $lines[] = $section['title'] . ': ' . count($section['rows']);
            foreach (array_slice($section['rows'], 0, self::DETAIL_LIMIT) as $row) {
                $lines[] = $row;
            }
            if (count($section['rows']) > self::DETAIL_LIMIT) {
                $lines[] = '…and ' . (count($section['rows']) - self::DETAIL_LIMIT) . ' more';
            }
        }
        return $this->trim(implode("\n", $lines), 4000);
    }

    private function sections(array $report): array
    {
        return [
            ['title' => 'Outages', 'rows' => array_map(
                static fn(array $row): string => '• ' . ($row['name'] ?: $row['ip']) . ' (' . $row['ip'] . ') · ' . $row['status'],
                $report['outages'],
            )],
            ['title' => 'New devices', 'rows' => array_map(
                static fn(array $row): string => '• ' . ($row['name'] ?: $row['ip']) . ' · ' . ($row['mac'] ?: $row['ip']),
                $report['new_devices'],
            )],
            ['title' => 'IP conflict events', 'rows' => array_map(
                static fn(array $row): string => '• ' . $row['ip'] . ' · ' . $row['event'] . ' · ' . $row['device_count'] . ' devices',
                $report['conflicts'],
            )],
            ['title' => 'Changed ports', 'rows' => array_map(
                static fn(array $row): string => '• ' . $row['ip'] . ' · ' . $row['port'] . '/' . $row['protocol'] . ' ' . $row['change_type'],
                $report['changed_ports'],
            )],
            ['title' => 'Expiring certificates', 'rows' => array_map(
                static fn(array $row): string => '• ' . $row['ip'] . ':' . $row['port'] . ' · ' . $row['expires_at'] . ' (' . $row['days_remaining'] . 'd)',
                $report['expiring_certificates'],
            )],
        ];
    }

    private function trim(string $value, int $length): string
    {
        return strlen($value) <= $length ? $value : rtrim(substr($value, 0, $length - 3)) . '...';
    }
}
