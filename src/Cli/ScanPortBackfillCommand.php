<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Scan\PortChangeService;

final readonly class ScanPortBackfillCommand implements Command
{
    public function __construct(private PortChangeService $changes, private LiveUpdatePublisher $updates) {}
    public function run(array $arguments): int
    {
        $inserted = $this->changes->backfill();
        if ($inserted > 0) $this->updates->publish(LiveUpdateScope::Scans);
        echo "scan port changes backfill: $inserted inserted" . PHP_EOL;
        return 0;
    }
}
