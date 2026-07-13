<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Docker\DockerNetworkRefreshService;
use Throwable;

final readonly class DockerNetworksRefreshCommand implements Command
{
    public function __construct(private DockerNetworkRefreshService $refresh)
    {
    }

    public function run(array $arguments): int
    {
        if ($arguments !== [] && $arguments !== ['--api']) {
            fwrite(STDERR, 'Usage: php cli.php docker-networks-refresh [--api]' . PHP_EOL);
            return 2;
        }
        try {
            $result = $this->refresh->refresh(
                force: $arguments === [],
                waitForLock: $arguments === [],
            );
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
