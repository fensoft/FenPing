<?php

declare(strict_types=1);

namespace FenPing\Network;

use FenPing\Process\ProcessRunner;
use Throwable;

final readonly class RouteDetector
{
    public function __construct(private ProcessRunner $processes) {}

    public function isRouted(Ipv4Network $network): bool
    {
        try {
            $result = $this->processes->run(['ip', '-4', 'route', 'show']);
        } catch (Throwable) {
            return false;
        }
        return $result->successful() && self::outputCovers($result->stdout, $network);
    }

    public static function outputCovers(string $output, Ipv4Network $network): bool
    {
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if ($parts === [] || $parts[0] === 'default' || in_array($parts[0], ['blackhole', 'unreachable', 'prohibit', 'throw'], true)) continue;
            if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?:\/(\d{1,2}))?$/', $parts[0], $matches)
                || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;
            $prefix = isset($matches[2]) ? (int) $matches[2] : 32;
            if ($prefix < 0 || $prefix > $network->prefixLength) continue;
            $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
            if ((ip2long($matches[1]) & $mask) === (ip2long($network->address) & $mask)) return true;
        }
        return false;
    }
}
