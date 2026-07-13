<?php

declare(strict_types=1);

namespace FenPing\Ipam;

use FenPing\Config\AppConfig;
use FenPing\Network\Ipv4Network;
use FenPing\Process\ProcessRunner;
use FenPing\Support\Clock;
use Throwable;

final readonly class IpConflictDetector
{
    public function __construct(
        private AppConfig $config,
        private ProcessRunner $processes,
        private IpConflictRepository $repository,
        private Clock $clock,
    ) {}

    public function scan(Ipv4Network $network): array
    {
        $attemptedAt = $this->clock->now();
        $target = $network->host(1) . '-' . $network->host(254);
        try {
            $result = $this->processes->run([
                '/usr/bin/arp-scan',
                '--interface=' . $this->config->interface,
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
                $this->config->applianceIp,
                $this->localMac($this->config->interface),
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
