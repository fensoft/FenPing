<?php

declare(strict_types=1);

namespace FenPing\Process;

interface ProcessRunner
{
    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult;
}
