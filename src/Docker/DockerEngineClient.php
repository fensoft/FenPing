<?php

declare(strict_types=1);

namespace FenPing\Docker;

use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;
use JsonException;
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
        $result = $this->request('/networks');
        if (!$result->successful()) {
            throw new RuntimeException(trim($result->stderr) ?: 'Docker network query failed');
        }
        try {
            $summaries = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Docker returned invalid network data', previous: $error);
        }
        if (!is_array($summaries) || !array_is_list($summaries)) {
            throw new RuntimeException('Docker returned invalid network data');
        }

        $networks = [];
        foreach ($summaries as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $id = is_string($summary['Id'] ?? null) ? trim($summary['Id']) : '';
            if ($id === '') {
                $id = is_string($summary['Name'] ?? null) ? trim($summary['Name']) : '';
            }
            if ($id === '') {
                $networks[] = $summary;
                continue;
            }

            $inspection = $this->request('/networks/' . rawurlencode($id));
            if (!$inspection->successful()) {
                $networks[] = $summary;
                continue;
            }
            try {
                $network = json_decode($inspection->stdout, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $networks[] = $summary;
                continue;
            }
            $networks[] = is_array($network) && !array_is_list($network) ? $network : $summary;
        }

        try {
            return $this->parser->parse(json_encode($networks, JSON_THROW_ON_ERROR));
        } catch (JsonException $error) {
            throw new RuntimeException('Docker returned invalid network data', previous: $error);
        }
    }

    private function request(string $path): ProcessResult
    {
        return $this->processes->run([
            'curl', '--fail', '--silent', '--show-error',
            '--connect-timeout', '2', '--max-time', '10',
            '--unix-socket', $this->socketPath,
            'http://localhost' . $path,
        ]);
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
