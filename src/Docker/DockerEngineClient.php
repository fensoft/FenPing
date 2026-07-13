<?php

declare(strict_types=1);

namespace FenPing\Docker;

use FenPing\Process\ProcessRunner;
use RuntimeException;

final readonly class DockerEngineClient implements DockerNetworkSource
{
    public function __construct(
        private ProcessRunner $processes,
        private DockerNetworkParser $parser,
        private string $socketPath,
    ) {
    }

    public function available(): bool
    {
        return $this->socketPath !== '' && @filetype($this->socketPath) === 'socket';
    }

    public function networks(): array
    {
        if (!$this->available()) {
            return [];
        }
        $result = $this->processes->run([
            'curl', '--fail', '--silent', '--show-error',
            '--connect-timeout', '2', '--max-time', '10',
            '--unix-socket', $this->socketPath,
            'http://localhost/networks',
        ]);
        if (!$result->successful()) {
            throw new RuntimeException(trim($result->stderr) ?: 'Docker network query failed');
        }
        return $this->parser->parse($result->stdout);
    }

    public function eventCommand(): array
    {
        $filters = rawurlencode(json_encode(['type' => ['network']], JSON_THROW_ON_ERROR));
        return [
            'curl', '--fail', '--silent', '--show-error', '--no-buffer',
            '--connect-timeout', '2',
            '--unix-socket', $this->socketPath,
            'http://localhost/events?filters=' . $filters,
        ];
    }
}
