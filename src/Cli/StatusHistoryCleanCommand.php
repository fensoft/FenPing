<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Config\AppConfig;
use FenPing\Status\StatusHistoryCleaner;

final readonly class StatusHistoryCleanCommand implements Command
{
    public function __construct(
        private AppConfig $config,
        private StatusHistoryCleaner $cleaner,
    ) {
    }

    public function run(array $arguments): int
    {
        if (count($arguments) > 2) {
            return $this->usage();
        }

        $retentionDays = $this->positiveInteger(
            $arguments[0] ?? null,
            $this->config->statusHistoryRetentionDays,
        );
        $maxEventsPerIp = $this->positiveInteger(
            $arguments[1] ?? null,
            $this->config->statusHistoryMaxEventsPerIp,
        );
        if ($retentionDays === null || $maxEventsPerIp === null) {
            return $this->usage();
        }

        $result = $this->cleaner->clean($retentionDays, $maxEventsPerIp);
        echo sprintf(
            'status history cleanup: %d deleted (%d expired, %d over limit), %d remaining; retention %d days, maximum %d events per IP',
            $result['deleted'],
            $result['deleted_by_age'],
            $result['deleted_by_limit'],
            $result['remaining'],
            $retentionDays,
            $maxEventsPerIp,
        ) . PHP_EOL;
        if ($result['compacted']) {
            echo sprintf(
                'SQLite compaction: %s -> %s (%s reclaimed)',
                $this->formatBytes($result['database_bytes_before']),
                $this->formatBytes($result['database_bytes_after']),
                $this->formatBytes($result['reclaimed_bytes']),
            ) . PHP_EOL;
        } else {
            echo sprintf(
                'SQLite compaction skipped: %s reclaimable of %s allocated',
                $this->formatBytes($result['reclaimable_bytes_before']),
                $this->formatBytes($result['database_bytes_before']),
            ) . PHP_EOL;
        }
        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        return sprintf('%.1f MiB', $bytes / 1024 / 1024);
    }

    private function positiveInteger(mixed $value, int $default): ?int
    {
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $parsed = (int) $value;
        return $parsed > 0 && (string) $parsed === ltrim($value, '0') ? $parsed : null;
    }

    private function usage(): int
    {
        fwrite(STDERR, 'Usage: php cli.php status-clean [retention-days] [max-events-per-ip]' . PHP_EOL);
        return 2;
    }
}
