<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Docker\DockerNetworkWatcher;

final readonly class DockerNetworksWatchCommand implements Command
{
    public function __construct(private DockerNetworkWatcher $watcher)
    {
    }

    public function run(array $arguments): int
    {
        if ($arguments !== []) {
            fwrite(STDERR, 'Usage: php cli.php docker-networks-watch' . PHP_EOL);
            return 2;
        }
        $this->watcher->runForever();
    }
}
