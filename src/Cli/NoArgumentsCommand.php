<?php

declare(strict_types=1);

namespace FenPing\Cli;

final readonly class NoArgumentsCommand implements Command
{
    public function __construct(private Command $command, private CliUsage $usage) {}

    public function run(array $arguments): int
    {
        return $arguments === [] ? $this->command->run([]) : $this->usage->write();
    }
}
