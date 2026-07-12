<?php

declare(strict_types=1);

namespace FenPing\Cli;

interface Command
{
    public function run(array $arguments): int;
}
