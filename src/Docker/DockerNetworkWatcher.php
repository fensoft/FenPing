<?php

declare(strict_types=1);

namespace FenPing\Docker;

use RuntimeException;
use Throwable;

final readonly class DockerNetworkWatcher
{
    private const ACTIONS = ['create', 'connect', 'disconnect', 'destroy', 'update', 'remove'];

    public function __construct(
        private DockerNetworkSource $source,
        private DockerNetworkRefreshService $refresh,
    ) {
    }

    public function runForever(): never
    {
        $backoff = 1;
        while (true) {
            try {
                if (!$this->source->available()) {
                    sleep($backoff);
                    $backoff = self::nextBackoff($backoff);
                    continue;
                }
                $this->refresh->refresh(true, true);
                $backoff = 1;
                $this->watchOnce();
                throw new RuntimeException('Docker event stream closed');
            } catch (Throwable $error) {
                fwrite(STDERR, 'Docker network watcher: ' . $error->getMessage() . PHP_EOL);
                sleep($backoff);
                $backoff = self::nextBackoff($backoff);
            }
        }
    }

    public static function nextBackoff(int $current): int
    {
        return min(30, max(1, $current * 2));
    }

    public static function isRelevantEvent(string $line): bool
    {
        $event = json_decode(trim($line), true);
        return is_array($event)
            && ($event['Type'] ?? null) === 'network'
            && in_array($event['Action'] ?? null, self::ACTIONS, true);
    }

    private function watchOnce(): void
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($this->source->eventCommand(), $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('failed to start Docker event stream');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffer = '';
        $stderr = '';
        $debouncer = new DockerNetworkEventDebouncer();
        try {
            while (true) {
                $now = microtime(true);
                if ($debouncer->due($now) && $debouncer->consume()) {
                    $this->refresh->refresh(true, true);
                }

                $read = [$pipes[1], $pipes[2]];
                $write = null;
                $except = null;
                $microseconds = min(999_999, $debouncer->remainingMicroseconds($now));
                $selected = stream_select($read, $write, $except, 0, $microseconds);
                if ($selected === false) {
                    throw new RuntimeException('failed to read Docker event stream');
                }
                foreach ($read as $stream) {
                    $chunk = (string) fread($stream, 8192);
                    if ($stream === $pipes[2]) {
                        $stderr .= $chunk;
                        continue;
                    }
                    $buffer .= $chunk;
                    while (($position = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $position);
                        $buffer = substr($buffer, $position + 1);
                        if (self::isRelevantEvent($line)) {
                            $debouncer->mark(microtime(true));
                        }
                    }
                }

                $status = proc_get_status($process);
                if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
                    if ($debouncer->consume()) {
                        $this->refresh->refresh(true, true);
                    }
                    $message = trim($stderr);
                    throw new RuntimeException($message !== '' ? $message : 'Docker event stream closed');
                }
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process);
            proc_close($process);
        }
    }
}
