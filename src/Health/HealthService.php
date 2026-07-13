<?php

declare(strict_types=1);

namespace FenPing\Health;

use FenPing\Backend\Backend;
use FenPing\Database\DatabaseManager;
use FenPing\Support\Clock;
use PDO;
use Throwable;

final readonly class HealthService
{
    public function __construct(
        private Backend $backend,
        private DatabaseManager $database,
        private OperationTracker $operations,
        private Clock $clock,
    ) {
    }

    public function liveness(): array
    {
        return [
            'status' => 'ok',
            'checked_at' => $this->now(),
            'web' => ['ok' => true, 'server' => $_SERVER['SERVER_SOFTWARE'] ?? '', 'php' => PHP_VERSION, 'sapi' => PHP_SAPI],
        ];
    }

    public function readiness(): array
    {
        $database = $this->backend->healthDb();
        $dnsmasq = $this->backend->healthProcess('dnsmasq', '/var/run/dnsmasq.pid');
        $cron = $this->backend->healthProcess('crond');
        $integrity = $this->integrity($this->operations->statuses());
        $reasons = [];
        if (!$database['ok']) {
            $reasons[] = 'database unavailable';
        }
        if (!$dnsmasq['running']) {
            $reasons[] = 'dnsmasq is not running';
        }
        if (!$cron['running']) {
            $reasons[] = 'cron is not running';
        }
        if ($integrity['status'] === 'failed') {
            $reasons[] = 'database integrity check failed';
        }

        return [
            'status' => $reasons === [] ? 'ok' : 'not_ready',
            'ready' => $reasons === [],
            'checked_at' => $this->now(),
            'reasons' => $reasons,
            'db' => $database,
            'dnsmasq' => $dnsmasq,
            'cron' => $cron,
            'integrity' => $integrity,
        ];
    }

    public function status(): array
    {
        $readiness = $this->readiness();
        $statuses = $this->operations->statuses();
        $recentFailures = $this->operations->recentFailures($this->backend->config->healthFailureWindowHours);
        $scans = $readiness['db']['ok'] ? $this->scans() : $this->emptyScans();
        $storage = $this->storage();
        $dhcp = $readiness['db']['ok'] ? $this->dhcp() : ['status' => 'unknown'];
        $lastPing = $readiness['db']['ok'] ? $this->backend->healthLastPingScan() : null;
        $lastInventory = $readiness['db']['ok'] ? $this->backend->healthLastInventoryScan() : null;
        $jobs = [
            'ping' => $this->job(
                'ping',
                $this->backend->config->healthPingMaxAgeMinutes * 60,
                $statuses,
                $lastPing['time'] ?? null,
            ),
            'discovery' => $this->job(
                'discovery',
                $this->backend->config->healthDiscoveryMaxAgeMinutes * 60,
                $statuses,
                $lastInventory['time'] ?? null,
            ),
            'lease_import' => $this->job('lease_import', $this->backend->config->healthLeaseImportMaxAgeMinutes * 60, $statuses),
            'oui_update' => $this->job(
                'oui_update',
                $this->backend->config->healthOuiMaxAgeDays * 86400,
                $statuses,
                $this->ouiUpdatedAt(),
            ),
            'backup' => $this->job(
                'backup',
                $this->backend->config->healthBackupMaxAgeDays * 86400,
                $statuses,
                $this->latestBackupAt(),
            ),
        ];
        $integrity = $readiness['integrity'];
        $exceptions = $readiness['db']['ok'] ? $this->networkExceptions() : [
            'new_devices' => null,
            'important_hosts_down' => null,
        ];
        $conflictDetection = $readiness['db']['ok']
            ? $this->backend->ipConflictMonitorStatus()
            : ['status' => 'unknown', 'monitors' => []];
        $dnsmasqFailures = $recentFailures['dnsmasq_generation'] ?? ['count' => 0, 'last_failure_at' => null];
        $notificationFailures = $recentFailures['notification_delivery'] ?? ['count' => 0, 'last_failure_at' => null];
        $dnsmasq = $readiness['dnsmasq'] + [
            'generation' => $this->operation('dnsmasq_generation', $statuses, $dnsmasqFailures),
        ];
        $notifications = [
            'enabled' => $this->backend->discordNotificationsEnabled(),
            'delivery' => $this->operation('notification_delivery', $statuses, $notificationFailures),
        ];

        $warning = $scans['failed'] > 0
            || $scans['timed_out'] > 0
            || $scans['queue_status'] === 'warning'
            || in_array(true, array_column($jobs, 'overdue'), true)
            || $dnsmasqFailures['count'] > 0
            || $notificationFailures['count'] > 0
            || in_array('failure', array_column($statuses, 'state'), true)
            || in_array($storage['status'], ['warning', 'critical'], true)
            || in_array($dhcp['status'] ?? 'unknown', ['warning', 'critical'], true)
            || $integrity['status'] === 'unknown';
        $critical = !$readiness['ready']
            || $storage['status'] === 'critical'
            || ($dhcp['status'] ?? '') === 'critical'
            || $conflictDetection['status'] === 'degraded';

        return [
            'status' => $critical ? 'degraded' : ($warning ? 'warning' : 'ok'),
            'checked_at' => $this->now(),
            'web' => $this->liveness()['web'],
            'db' => $readiness['db'],
            'dnsmasq' => $dnsmasq,
            'cron' => $readiness['cron'],
            'readiness' => $readiness,
            'scans' => $scans,
            'jobs' => $jobs,
            'storage' => $storage,
            'integrity' => $integrity,
            'dhcp' => $dhcp,
            'notifications' => $notifications,
            'exceptions' => $exceptions,
            'thresholds' => $this->thresholds(),
            'ip_conflict_detection' => $conflictDetection,
            'last_ping_scan_time' => $jobs['ping']['last_success_at'],
            'last_ping_scan_age_seconds' => $jobs['ping']['age_seconds'],
            'last_inventory_scan_time' => $jobs['discovery']['last_success_at'],
            'last_inventory_scan_age_seconds' => $jobs['discovery']['age_seconds'],
            'last_inventory_scan' => $lastInventory['scan'] ?? null,
        ];
    }

    private function scans(): array
    {
        try {
            $hours = $this->backend->config->healthFailureWindowHours;
            $row = $this->database->connection()->query("
                SELECT
                  SUM(CASE WHEN state='queued' THEN 1 ELSE 0 END) AS queued,
                  SUM(CASE WHEN state='running' THEN 1 ELSE 0 END) AS running,
                  SUM(CASE WHEN state='failed' AND date_end>=datetime('now', '-$hours hours') THEN 1 ELSE 0 END) AS failed,
                  SUM(CASE WHEN state='timeout' AND date_end>=datetime('now', '-$hours hours') THEN 1 ELSE 0 END) AS timed_out,
                  MIN(CASE WHEN state='queued' THEN queued_at END) AS oldest_queued_at
                FROM scans
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            $age = $this->age($row['oldest_queued_at'] ?? null);
            return [
                'queued' => (int) ($row['queued'] ?? 0),
                'running' => (int) ($row['running'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
                'timed_out' => (int) ($row['timed_out'] ?? 0),
                'oldest_queued_at' => $row['oldest_queued_at'] ?? null,
                'oldest_queued_age_seconds' => $age,
                'queue_status' => $age !== null
                    && $age > $this->backend->config->healthScanQueueMaxAgeMinutes * 60
                    ? 'warning' : 'ok',
                'window_hours' => $hours,
            ];
        } catch (Throwable) {
            return $this->emptyScans();
        }
    }

    private function emptyScans(): array
    {
        return [
            'queued' => 0, 'running' => 0, 'failed' => 0, 'timed_out' => 0,
            'oldest_queued_at' => null, 'oldest_queued_age_seconds' => null,
            'queue_status' => 'unknown',
            'window_hours' => $this->backend->config->healthFailureWindowHours,
        ];
    }

    private function job(string $name, int $maxAge, array $statuses, ?string $fallback = null): array
    {
        $operation = $statuses[$name] ?? [];
        $success = $operation['last_success_at'] ?? $fallback;
        $age = $this->age($success);
        return [
            'state' => $operation['state'] ?? ($success === null ? 'unknown' : 'success'),
            'last_success_at' => $success,
            'last_failure_at' => $operation['last_failure_at'] ?? null,
            'last_error' => $operation['last_error'] ?? null,
            'age_seconds' => $age,
            'max_age_seconds' => $maxAge,
            'overdue' => $age === null || $age > $maxAge,
        ];
    }

    private function operation(string $name, array $statuses, array $recent): array
    {
        $operation = $statuses[$name] ?? [];
        return [
            'state' => $operation['state'] ?? 'unknown',
            'last_success_at' => $operation['last_success_at'] ?? null,
            'last_failure_at' => $operation['last_failure_at'] ?? null,
            'last_error' => $operation['last_error'] ?? null,
            'recent_failures' => (int) ($recent['count'] ?? 0),
            'window_hours' => $this->backend->config->healthFailureWindowHours,
        ];
    }

    private function integrity(array $statuses): array
    {
        $status = $statuses['database_integrity'] ?? null;
        return [
            'status' => $status === null ? 'unknown' : ($status['state'] === 'failure' ? 'failed' : 'ok'),
            'checked_at' => $status['last_finished_at'] ?? null,
            'last_success_at' => $status['last_success_at'] ?? null,
            'error' => $status['last_error'] ?? null,
        ];
    }

    private function storage(): array
    {
        $directory = dirname($this->backend->config->databasePath);
        $total = @disk_total_space($directory);
        $free = @disk_free_space($directory);
        $usedPercent = is_float($total) && $total > 0 && is_float($free)
            ? round(($total - $free) * 100 / $total, 1) : null;
        $status = $usedPercent === null ? 'unknown' : (
            $usedPercent >= $this->backend->config->healthDiskCriticalPercent ? 'critical' : (
                $usedPercent >= $this->backend->config->healthDiskWarningPercent ? 'warning' : 'ok'
            )
        );
        return [
            'status' => $status,
            'sqlite_bytes' => is_file($this->backend->config->databasePath)
                ? filesize($this->backend->config->databasePath) : null,
            'wal_bytes' => is_file($this->backend->config->databasePath . '-wal')
                ? filesize($this->backend->config->databasePath . '-wal') : 0,
            'disk_total_bytes' => is_float($total) ? (int) $total : null,
            'disk_free_bytes' => is_float($free) ? (int) $free : null,
            'disk_used_percent' => $usedPercent,
        ];
    }

    private function dhcp(): array
    {
        try {
            $pool = $this->backend->ipamPoolUtilization($this->backend->ipamPoolConfig());
            $used = (float) $pool['utilization_percent'];
            $pool['status'] = $used >= $this->backend->config->healthDhcpCriticalPercent
                ? 'critical' : ($used >= $this->backend->config->healthDhcpWarningPercent ? 'warning' : 'ok');
            return $pool;
        } catch (Throwable $error) {
            return ['status' => 'unknown', 'error' => $error->getMessage()];
        }
    }

    private function networkExceptions(): array
    {
        try {
            $pending = count($this->backend->getIpam()['pending'] ?? []);
            $down = 0;
            foreach ($this->backend->getInventory($this->backend->config->dhcpNetwork->cidr) as $host) {
                if ((int) ($host['important'] ?? 0) === 1
                    && !in_array((string) ($host['status'] ?? ''), ['Up', 'arp'], true)) {
                    $down++;
                }
            }
            return ['new_devices' => $pending, 'important_hosts_down' => $down];
        } catch (Throwable) {
            return ['new_devices' => null, 'important_hosts_down' => null];
        }
    }

    private function ouiUpdatedAt(): ?string
    {
        try {
            $path = $this->backend->config->stateDir() . '/ieee-oui.json';
            if (!is_file($path) || !is_readable($path)) {
                return null;
            }
            $document = json_decode((string) file_get_contents(
                $path,
            ), true, flags: JSON_THROW_ON_ERROR);
            return is_string($document['updated_at'] ?? null) ? $document['updated_at'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function latestBackupAt(): ?string
    {
        $latest = null;
        $paths = array_merge(
            glob($this->backend->config->backupDir() . '/*.tgz') ?: [],
            glob($this->backend->config->backupDir() . '/*.tar.gz') ?: [],
        );
        foreach ($paths as $path) {
            $metadataPath = $path . '.metadata.json';
            $metadata = is_readable($metadataPath)
                ? json_decode((string) @file_get_contents($metadataPath), true)
                : null;
            if (($metadata['verification']['status'] ?? '') === 'failed') {
                continue;
            }
            $time = filemtime($path);
            if ($time !== false && ($latest === null || $time > $latest)) {
                $latest = $time;
            }
        }
        return $latest === null ? null : gmdate('Y-m-d H:i:s', $latest);
    }

    private function thresholds(): array
    {
        $config = $this->backend->config;
        return [
            'failure_window_hours' => $config->healthFailureWindowHours,
            'ping_max_age_minutes' => $config->healthPingMaxAgeMinutes,
            'discovery_max_age_minutes' => $config->healthDiscoveryMaxAgeMinutes,
            'lease_import_max_age_minutes' => $config->healthLeaseImportMaxAgeMinutes,
            'oui_max_age_days' => $config->healthOuiMaxAgeDays,
            'backup_max_age_days' => $config->healthBackupMaxAgeDays,
            'scan_queue_max_age_minutes' => $config->healthScanQueueMaxAgeMinutes,
            'disk_warning_percent' => $config->healthDiskWarningPercent,
            'disk_critical_percent' => $config->healthDiskCriticalPercent,
            'dhcp_warning_percent' => $config->healthDhcpWarningPercent,
            'dhcp_critical_percent' => $config->healthDhcpCriticalPercent,
        ];
    }

    private function age(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }
        $timestamp = strtotime($time);
        return $timestamp === false ? null : max(0, $this->clock->now()->getTimestamp() - $timestamp);
    }

    private function now(): string
    {
        return $this->clock->now()->format(DATE_ATOM);
    }
}
