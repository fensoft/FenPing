<?php

declare(strict_types=1);

namespace FenPing\Cli;


final class CliKernel
{
    /** @param array<string, Command> $commands */
    public function __construct(private readonly array $commands, private readonly CliUsage $usage)
    {
        foreach ($commands as $name => $command) {
            if (!is_string($name) || $name === '' || !$command instanceof Command) {
                throw new \InvalidArgumentException('invalid CLI command map');
            }
        }
    }

    public function run(array $argv): int
    {
        $name = (string) ($argv[1] ?? '');
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            return $this->usage->write();
        }
        return $command->run(array_slice($argv, 2));
    }

}
