<?php

declare(strict_types=1);

namespace FenPing\Realtime;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class NchanLiveUpdatePublisher implements LiveUpdatePublisher
{
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 80,
        private string $path = '/internal/live-updates',
        private float $timeoutSeconds = 0.2,
    ) {
    }

    public function publish(LiveUpdateScope ...$scopes): void
    {
        try {
            $payload = self::payload($scopes);
            if ($payload === null) {
                return;
            }
            $socket = @stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errorCode,
                $errorMessage,
                $this->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
            );
            if ($socket === false) {
                return;
            }
            try {
                $microseconds = max(1, (int) round($this->timeoutSeconds * 1_000_000));
                @stream_set_timeout($socket, intdiv($microseconds, 1_000_000), $microseconds % 1_000_000);
                $request = "POST {$this->path} HTTP/1.0\r\n"
                    . "Host: {$this->host}\r\n"
                    . "Content-Type: application/json\r\n"
                    . "X-EventSource-Event: fenping-update\r\n"
                    . 'Content-Length: ' . strlen($payload) . "\r\n"
                    . "Connection: close\r\n\r\n"
                    . $payload;
                $this->write($socket, $request);
                @fflush($socket);
                @fgets($socket);
            } finally {
                fclose($socket);
            }
        } catch (Throwable) {
            // Live updates are hints and must never change the operation outcome.
        }
    }

    /** @param list<LiveUpdateScope> $scopes */
    public static function payload(array $scopes, ?DateTimeImmutable $occurredAt = null): ?string
    {
        $values = [];
        foreach ($scopes as $scope) {
            $values[$scope->value] = true;
        }
        if ($values === []) {
            return null;
        }
        if (isset($values[LiveUpdateScope::All->value])) {
            $names = [LiveUpdateScope::All->value];
        } else {
            $names = array_keys($values);
            sort($names, SORT_STRING);
        }
        $occurredAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
        return json_encode([
            'version' => 1,
            'scopes' => $names,
            'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param resource $socket */
    private function write($socket, string $request): void
    {
        $offset = 0;
        $length = strlen($request);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($request, $offset));
            if ($written === false || $written === 0) {
                return;
            }
            $offset += $written;
        }
    }
}
