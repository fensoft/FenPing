<?php

declare(strict_types=1);

namespace FenPing\Docker;

use FenPing\Process\ProcessRunner;
use JsonException;
use RuntimeException;

final readonly class PrivilegedDockerNetworkRefreshGateway implements DockerNetworkRefreshGateway
{
    public function __construct(private ProcessRunner $processes, private string $projectDir)
    {
    }

    public function refresh(): array
    {
        $result = $this->processes->run([
            '/usr/bin/doas', '/usr/bin/php', $this->projectDir . '/cli.php',
            'docker-networks-refresh', '--api',
        ]);
        if (!$result->successful()) {
            throw new RuntimeException(trim($result->stderr) ?: 'Docker network refresh failed');
        }
        try {
            $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Docker network refresh returned invalid data', previous: $error);
        }
        if (!is_array($data)
            || !is_string($data['status'] ?? null)
            || !in_array($data['status'], ['refreshed', 'unchanged', 'skipped', 'stale'], true)
            || !is_int($data['networks'] ?? null)
            || (!is_int($data['updated_at'] ?? null) && ($data['updated_at'] ?? null) !== null)) {
            throw new RuntimeException('Docker network refresh returned invalid data');
        }
        return [
            'status' => $data['status'],
            'networks' => $data['networks'],
            'updated_at' => $data['updated_at'],
        ];
    }
}
