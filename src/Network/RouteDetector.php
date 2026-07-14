<?php

declare(strict_types=1);

namespace FenPing\Network;

use FenPing\Process\ProcessRunner;
use Throwable;

final readonly class RouteDetector
{
    public function __construct(private ProcessRunner $processes) {}

    /** @return array{status: string, routes: list<array{destination: string, address: string, prefix_length: int, gateway: ?string, interface: ?string, source: ?string}>} */
    public function inspect(): array
    {
        try {
            $result = $this->processes->run(['ip', '-4', 'route', 'show']);
        } catch (Throwable) {
            return ['status' => 'unavailable', 'routes' => []];
        }
        if (!$result->successful()) {
            return ['status' => 'unavailable', 'routes' => []];
        }
        return ['status' => 'ok', 'routes' => self::parse($result->stdout)];
    }

    public function isRouted(Ipv4Network $network): bool
    {
        return self::coveringRoute($this->inspect()['routes'], $network) !== null;
    }

    public static function outputCovers(string $output, Ipv4Network $network): bool
    {
        return self::coveringRoute(self::parse($output), $network) !== null;
    }

    /** @return list<array{destination: string, address: string, prefix_length: int, gateway: ?string, interface: ?string, source: ?string}> */
    public static function parse(string $output): array
    {
        $routes = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if ($parts === [] || $parts[0] === 'default' || in_array($parts[0], ['blackhole', 'unreachable', 'prohibit', 'throw'], true)) {
                continue;
            }
            if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?:\/(\d{1,2}))?$/', $parts[0], $matches)
                || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $prefix = isset($matches[2]) ? (int) $matches[2] : 32;
            if ($prefix < 0 || $prefix > 32) {
                continue;
            }
            $addressLong = ip2long($matches[1]);
            if ($addressLong === false) {
                continue;
            }
            $mask = self::mask($prefix);
            $address = long2ip($addressLong & $mask);
            if ($address === false) {
                continue;
            }
            $routes[] = [
                'destination' => $address . '/' . $prefix,
                'address' => $address,
                'prefix_length' => $prefix,
                'gateway' => self::field($parts, 'via', true),
                'interface' => self::field($parts, 'dev'),
                'source' => self::field($parts, 'src', true),
            ];
        }
        return $routes;
    }

    /**
     * @param list<array{destination: string, address: string, prefix_length: int, gateway: ?string, interface: ?string, source: ?string}> $routes
     * @return array{destination: string, address: string, prefix_length: int, gateway: ?string, interface: ?string, source: ?string}|null
     */
    public static function coveringRoute(array $routes, Ipv4Network $network): ?array
    {
        $best = null;
        foreach ($routes as $route) {
            $prefix = (int) ($route['prefix_length'] ?? 33);
            if ($prefix < 0 || $prefix > $network->prefixLength) {
                continue;
            }
            $routeAddress = ip2long((string) ($route['address'] ?? ''));
            $networkAddress = ip2long($network->address);
            if ($routeAddress === false || $networkAddress === false) {
                continue;
            }
            $mask = self::mask($prefix);
            if (($routeAddress & $mask) !== ($networkAddress & $mask)) {
                continue;
            }
            if ($best === null || $prefix > $best['prefix_length']) {
                $best = $route;
            }
        }
        return $best;
    }

    private static function mask(int $prefix): int
    {
        return $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
    }

    private static function field(array $parts, string $name, bool $ipv4 = false): ?string
    {
        $index = array_search($name, $parts, true);
        if ($index === false || !isset($parts[$index + 1])) {
            return null;
        }
        $value = trim((string) $parts[$index + 1]);
        if ($value === '' || ($ipv4 && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) {
            return null;
        }
        return $value;
    }
}
