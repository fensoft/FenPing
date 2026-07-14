<?php

declare(strict_types=1);

namespace FenPing\Cli;

final readonly class LockingCommand implements Command
{
    public function __construct(private Command $command, private string $path, private string $label) {}

    public function run(array $arguments): int
    {
        $lock = fopen($this->path, 'c');
        if ($lock === false) {
            fwrite(STDERR, "failed to open {$this->label} lock" . PHP_EOL);
            return 1;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            fwrite(STDERR, "{$this->label} already running" . PHP_EOL);
            return 75;
        }
        try { return $this->command->run($arguments); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
