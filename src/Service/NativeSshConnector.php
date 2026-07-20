<?php

declare(strict_types=1);

namespace FenPing\Service;

use RuntimeException;

final readonly class NativeSshConnector implements SshConnector
{
    public function banner(string $host, int $port, float $timeoutSeconds): string
    {
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $socket = @stream_socket_client(
            'tcp://' . $address . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new RuntimeException($errorMessage !== '' ? $errorMessage : 'SSH connection failed');
        }
        try {
            $seconds = (int) $timeoutSeconds;
            $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);
            stream_set_timeout($socket, $seconds, $microseconds);
            $banner = fgets($socket, 256);
            $metadata = stream_get_meta_data($socket);
            if (!empty($metadata['timed_out'])) {
                throw new RuntimeException('SSH banner timed out');
            }
            if ($banner === false || !str_starts_with($banner, 'SSH-')) {
                throw new RuntimeException('server did not return an SSH banner');
            }
            return trim($banner);
        } finally {
            fclose($socket);
        }
    }
}
