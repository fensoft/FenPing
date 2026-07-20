<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Service\MonitoredServiceManager;
use InvalidArgumentException;

final readonly class ServiceCheckCommand implements Command
{
    public function __construct(private MonitoredServiceManager $services)
    {
    }

    public function run(array $arguments): int
    {
        if ($arguments !== []) {
            throw new InvalidArgumentException('Usage: php cli.php service-check');
        }
        $checked = $this->services->checkAll();
        $healthy = count(array_filter($checked, static fn(array $service): bool => $service['check_status'] === 'healthy'));
        echo 'checked ' . count($checked) . " manual services; $healthy healthy" . PHP_EOL;
        return 0;
    }
}
