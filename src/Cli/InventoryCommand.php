<?php

declare(strict_types=1);

namespace FenPing\Cli;

final readonly class InventoryCommand implements Command
{
    public function __construct(private Command $scheduled, private Command $worker, private Command $job) {}
    public function run(array $arguments): int
    {
        if (($arguments[0] ?? '') === '--work') return $this->worker->run($arguments);
        if (($arguments[0] ?? '') === '--run-job') return $this->job->run($arguments);
        return $this->scheduled->run($arguments);
    }
}
