<?php

declare(strict_types=1);

namespace FenPing\Ipam;

use FenPing\Config\AppConfig;
use FenPing\Network\Ipv4Network;
use FenPing\Network\RouteDetector;
use FenPing\Process\ProcessRunner;
use FenPing\Support\Clock;
use Throwable;

final readonly class IpConflictDetector implements IpConflictScanner
{
    public function __construct(
        private AppConfig $config,
        private ProcessRunner $processes,
        private RouteDetector $routes,
        private IpConflictRepository $repository,
        private Clock $clock,
    ) {}

    public function scan(Ipv4Network $network): array
    {
        $attemptedAt = $this->clock->now();
        $route = $this->scanRoute($network);
        if ($route['error'] !== null) {
            $this->repository->recordFailure($network, $attemptedAt, $route['error']);
            return ['successful' => false, 'transitions' => [], 'error' => $route['error']];
        }
        $target = $network->host(1) . '-' . $network->host(254);
        try {
            $result = $this->processes->run([
                '/usr/bin/arp-scan',
                '--interface=' . $route['interface'],
                '--quiet',
                '--plain',
                '--retry=2',
                $target,
            ]);
            if (!$result->successful()) {
                $error = trim($result->stderr) ?: 'arp-scan exited with status ' . $result->exitCode;
                $this->repository->recordFailure($network, $attemptedAt, $error);
                return ['successful' => false, 'transitions' => [], 'error' => $error];
            }
            $conflicts = $this->parse(
                $result->stdout,
                $network,
                $route['source'],
                $this->localMac($route['interface']),
            );
            return [
                'successful' => true,
                'transitions' => $this->repository->reconcile($network, $conflicts, $attemptedAt),
                'error' => null,
            ];
        } catch (Throwable $error) {
            $this->repository->recordFailure($network, $attemptedAt, $error->getMessage());
            return ['successful' => false, 'transitions' => [], 'error' => $error->getMessage()];
        }
    }

    /** @return array{interface: string, source: string, error: ?string} */
    private function scanRoute(Ipv4Network $network): array
    {
        $inspection = $this->routes->inspect();
        if ($inspection['status'] !== 'ok') {
            return [
                'interface' => $this->config->interface,
                'source' => $this->config->applianceIp,
                'error' => null,
            ];
        }

        $route = RouteDetector::coveringRoute($inspection['routes'], $network);
        if ($route === null) {
            return [
                'interface' => '',
                'source' => '',
                'error' => "No route covers {$network->cidr}",
            ];
        }
        if ($route['gateway'] !== null) {
            return [
                'interface' => '',
                'source' => '',
                'error' => "IP conflict detection requires a directly connected network: {$network->cidr}",
            ];
        }

        $interface = trim((string) ($route['interface'] ?? ''));
        if ($interface === '') {
            return [
                'interface' => '',
                'source' => '',
                'error' => "Route for {$network->cidr} has no interface",
            ];
        }
        $source = trim((string) ($route['source'] ?? ''));
        return [
            'interface' => $interface,
            'source' => $source !== '' ? $source : $this->config->applianceIp,
            'error' => null,
        ];
    }

    public function parse(string $output, Ipv4Network $network, string $localIp = '', string $localMac = ''): array
    {
        $observations = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (!is_array($fields) || count($fields) < 2) {
                continue;
            }
            $this->addObservation($observations, $network, (string) $fields[0], (string) $fields[1]);
        }
        $this->addObservation($observations, $network, $localIp, $localMac);

        $conflicts = [];
        foreach ($observations as $ip => $macs) {
            if (count($macs) >= 2) {
                ksort($macs, SORT_STRING);
                $conflicts[$ip] = $macs;
            }
        }
        ksort($conflicts, SORT_NATURAL);
        return $conflicts;
    }

    public function localMac(string $interface): string
    {
        if ($interface === '') {
            return '';
        }
        $path = '/sys/class/net/' . basename($interface) . '/address';
        if (!is_readable($path)) {
            return '';
        }
        $value = file_get_contents($path);
        return $value === false ? '' : trim($value);
    }

    private function addObservation(array &$observations, Ipv4Network $network, string $ip, string $mac): void
    {
        $ip = trim($ip);
        $mac = strtolower(trim($mac));
        if (!$network->contains($ip) || !$this->hostAddress($ip) || !$this->validMac($mac)) {
            return;
        }
        $observations[$ip][$mac] = true;
    }

    private function hostAddress(string $ip): bool
    {
        $position = strrpos($ip, '.');
        if ($position === false) {
            return false;
        }
        $octet = substr($ip, $position + 1);
        return ctype_digit($octet) && (int) $octet >= 1 && (int) $octet <= 254;
    }

    private function validMac(string $mac): bool
    {
        if (preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) !== 1
            || $mac === '00:00:00:00:00:00' || $mac === 'ff:ff:ff:ff:ff:ff') {
            return false;
        }
        return (hexdec(substr($mac, 0, 2)) & 1) === 0;
    }
}
