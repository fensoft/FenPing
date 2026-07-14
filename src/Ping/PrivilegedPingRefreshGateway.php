<?php

declare(strict_types=1);

namespace FenPing\Ping;

use FenPing\Config\AppConfig;
use RuntimeException;

final readonly class PrivilegedPingRefreshGateway implements PingRefreshGateway
{
    public function __construct(private AppConfig $config)
    {
    }

    public function refresh(string $networkCidr): void
    {
        $previous = getenv('SCAN_NETWORK');
        putenv('SCAN_NETWORK=' . $networkCidr);
        try {
            $command = '/usr/bin/doas /usr/bin/php '
                . escapeshellarg($this->config->projectDir . '/cli.php')
                . ' ping';
            $output = [];
            $code = 0;
            exec($command . ' 2>&1', $output, $code);
        } finally {
            $previous === false ? putenv('SCAN_NETWORK') : putenv('SCAN_NETWORK=' . $previous);
        }
        if ($code !== 0) {
            throw new RuntimeException(trim(implode("\n", $output)) ?: 'scan already running');
        }
    }
}
