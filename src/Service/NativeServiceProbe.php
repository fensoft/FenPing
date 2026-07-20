<?php

declare(strict_types=1);

namespace FenPing\Service;

use FenPing\Process\ProcessRunner;
use RuntimeException;
use Throwable;

final readonly class NativeServiceProbe implements ServiceProbe
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TOTAL_TIMEOUT_SECONDS = 10;

    public function __construct(
        private ProcessRunner $processes,
        private SshConnector $ssh,
    ) {
    }

    public function check(array $service): ServiceProbeResult
    {
        try {
            return match ((string) ($service['type'] ?? '')) {
                'https' => $this->https((string) $service['target']),
                'ssh' => $this->ssh((string) $service['target'], (int) $service['port']),
                'proxy' => $this->proxy((string) $service['target'], (int) $service['port'], 'http'),
                'socks5' => $this->proxy((string) $service['target'], (int) $service['port'], 'socks5h'),
                default => throw new RuntimeException('unsupported manual service type'),
            };
        } catch (Throwable $error) {
            return new ServiceProbeResult(false, $this->sanitize($error->getMessage()));
        }
    }

    private function https(string $url): ServiceProbeResult
    {
        $result = $this->processes->run([
            'curl', '--silent', '--show-error', '--output', '/dev/null', '--write-out', '%{http_code}',
            '--connect-timeout', (string) self::CONNECT_TIMEOUT_SECONDS,
            '--max-time', (string) self::TOTAL_TIMEOUT_SECONDS,
            '--location', '--max-redirs', '3', '--proto', '=https', '--proto-redir', '=https',
            '--user-agent', 'FenPing service checker', $url,
        ]);
        if (!$result->successful()) {
            throw new RuntimeException(trim($result->stderr) ?: 'HTTPS request failed');
        }
        $status = trim($result->stdout);
        if (preg_match('/^[1-5][0-9]{2}$/', $status) !== 1) {
            throw new RuntimeException('HTTPS server returned an invalid response');
        }
        $code = (int) $status;
        return new ServiceProbeResult($code >= 200 && $code < 300, 'HTTP ' . $code);
    }

    private function ssh(string $host, int $port): ServiceProbeResult
    {
        $banner = $this->ssh->banner($host, $port, self::CONNECT_TIMEOUT_SECONDS);
        return new ServiceProbeResult(true, $this->sanitize($banner));
    }

    private function proxy(string $host, int $port, string $scheme): ServiceProbeResult
    {
        $proxyHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $result = $this->processes->run([
            'curl', '--fail', '--silent', '--show-error',
            '--connect-timeout', (string) self::CONNECT_TIMEOUT_SECONDS,
            '--max-time', (string) self::TOTAL_TIMEOUT_SECONDS,
            '--proto', '=https', '--noproxy', '',
            '--proxy', $scheme . '://' . $proxyHost . ':' . $port,
            'https://ifconfig.me/ip',
        ]);
        if (!$result->successful()) {
            throw new RuntimeException(trim($result->stderr) ?: 'proxy request failed');
        }
        $ip = trim($result->stdout);
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            throw new RuntimeException('proxy response was not a public IP address');
        }
        return new ServiceProbeResult(true, 'Proxy exit ' . $ip, $ip);
    }

    private function sanitize(string $message): string
    {
        $message = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? '');
        $message = preg_replace('/\s+/', ' ', $message) ?? '';
        return substr($message !== '' ? $message : 'connection failed', 0, 240);
    }
}
