<?php

declare(strict_types=1);

namespace FenPing\Doctor;

use FenPing\Config\AppConfig;
use FenPing\Process\ProcessRunner;
use JsonException;
use RuntimeException;

final readonly class ProcessDoctorReportProvider implements DoctorReportProvider
{
    public function __construct(private AppConfig $config, private ProcessRunner $processes)
    {
    }

    public function runtimeReport(): array
    {
        $result = $this->processes->run([
            '/usr/bin/doas', '/usr/bin/php', $this->config->projectDir . '/cli.php',
            'doctor', '--runtime', '--json',
        ]);
        if (!in_array($result->exitCode, [0, 1], true)) {
            throw new RuntimeException(trim($result->stderr) ?: 'privileged doctor command failed');
        }
        try {
            $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('doctor returned invalid JSON', previous: $error);
        }
        if (!is_array($report)
            || !in_array($report['status'] ?? null, ['ok', 'failed'], true)
            || !is_string($report['checked_at'] ?? null)
            || !is_array($report['checks'] ?? null)) {
            throw new RuntimeException('doctor returned an invalid report');
        }
        return $report;
    }
}
