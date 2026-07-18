<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Report\ScheduledReportService;

final readonly class ScheduledReportCommand implements Command
{
    public function __construct(private ScheduledReportService $reports, private CliUsage $usage) {}

    public function run(array $arguments): int
    {
        if ($arguments !== []) {
            return $this->usage->write();
        }
        $failed = false;
        foreach ($this->reports->runDue() as $frequency => $state) {
            echo $frequency . ' report: ' . $state . PHP_EOL;
            $failed = $failed || $state === 'failure';
        }
        return $failed ? 1 : 0;
    }
}
