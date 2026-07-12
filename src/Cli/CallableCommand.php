<?php

declare(strict_types=1);

namespace FenPing\Cli;

use Closure;

final readonly class CallableCommand implements Command
{
    public function __construct(private Closure $callback)
    {
    }

    public function run(array $arguments): int
    {
        return ($this->callback)($arguments);
    }
}
