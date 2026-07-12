<?php

declare(strict_types=1);

namespace FenPing\Process;

use RuntimeException;

final class NativeProcessRunner implements ProcessRunner
{
    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        if ($command === []) {
            throw new RuntimeException('process command is empty');
        }
        $descriptors = [
            0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
            1 => $stdoutFile === null ? ['pipe', 'w'] : ['file', $stdoutFile, 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, $environment === [] ? null : $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('failed to start process');
        }
        if ($stdinFile === null) {
            fclose($pipes[0]);
        }
        $stdout = $stdoutFile === null ? (string) stream_get_contents($pipes[1]) : '';
        if ($stdoutFile === null) {
            fclose($pipes[1]);
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return new ProcessResult(proc_close($process), $stdout, $stderr);
    }
}
