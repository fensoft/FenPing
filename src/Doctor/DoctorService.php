<?php

declare(strict_types=1);

namespace FenPing\Doctor;

use FenPing\Config\AppConfig;
use FenPing\Process\ProcessRunner;
use FenPing\Support\Clock;
use RuntimeException;
use Throwable;

final readonly class DoctorService
{
    public function __construct(
        private AppConfig $config,
        private ProcessRunner $processes,
        private DoctorSystem $system,
        private Clock $clock,
    ) {
    }

    public function inspect(DoctorMode $mode = DoctorMode::Startup): DoctorReport
    {
        $interface = trim($this->config->interface);
        [$interfaceCheck, $addresses] = $this->checkInterface($interface);
        $checks = [
            $interfaceCheck,
            $this->checkSubnet($interface, $addresses),
            $this->checkRouter($interface, $interfaceCheck->passed),
            $this->checkPool(),
            $this->checkPorts($interface, $interfaceCheck->passed, $mode),
            $this->checkStorage(),
            $this->checkDhcpServer($interface, $interfaceCheck->passed, $mode),
        ];
        return new DoctorReport($this->clock->now()->format(DATE_ATOM), $checks);
    }

    /** @return array{DoctorCheck, list<string>} */
    private function checkInterface(string $interface): array
    {
        $errors = [];
        if ($interface === '' || basename($interface) !== $interface) {
            $errors[] = 'IFACE is missing or invalid';
        } elseif ($interface === 'lo') {
            $errors[] = 'the loopback interface cannot serve a LAN';
        } elseif (!$this->system->interfaceExists($interface)) {
            $errors[] = "interface $interface does not exist";
        } elseif (!$this->system->interfaceUp($interface)) {
            $errors[] = "interface $interface is not administratively up";
        }

        $addresses = [];
        if ($errors === []) {
            try {
                $result = $this->processes->run(['ip', '-o', '-4', 'address', 'show', 'dev', $interface]);
                if (!$result->successful()) {
                    $errors[] = trim($result->stderr) ?: 'failed to inspect IPv4 addresses';
                } else {
                    $addresses = self::parseInterfaceAddresses($result->stdout);
                    if ($addresses === []) {
                        $errors[] = "interface $interface has no IPv4 address";
                    }
                }
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }

        return [new DoctorCheck(
            'interface',
            $errors === [],
            $errors === [] ? "$interface is up with IPv4 address " . implode(', ', $addresses) : implode('; ', $errors),
            $errors === [] ? '' : 'Set IFACE to an active non-loopback LAN interface.',
        ), $addresses];
    }

    private function checkSubnet(string $interface, array $addresses): DoctorCheck
    {
        $ip = trim($this->config->applianceIp);
        $errors = [];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = 'IP is not a valid IPv4 address';
        } else {
            if (!$this->config->dhcpNetwork->contains($ip)) {
                $errors[] = "$ip is outside {$this->config->dhcpNetwork->cidr}";
            }
            if (!in_array($ip, $addresses, true)) {
                $errors[] = "$ip is not assigned to $interface";
            }
            $peer = $this->config->dhcpNetwork->host($ip === $this->config->dhcpNetwork->host(1) ? 2 : 1);
            $route = $this->routeDevice($peer);
            if ($route !== $interface) {
                $errors[] = $route === null
                    ? "no usable route to {$this->config->dhcpNetwork->cidr}"
                    : "{$this->config->dhcpNetwork->cidr} routes through $route instead of $interface";
            }
        }
        return new DoctorCheck(
            'subnet',
            $errors === [],
            $errors === [] ? "{$this->config->dhcpNetwork->cidr} is on $interface at $ip" : implode('; ', $errors),
            $errors === [] ? '' : 'Configure IP and DHCP_NETWORK for the selected interface and its connected /24.',
        );
    }

    private function checkRouter(string $interface, bool $interfaceReady): DoctorCheck
    {
        $router = trim($this->config->dhcpDefaultRouter);
        if ($router === '') {
            return new DoctorCheck(
                'router',
                true,
                'No default router is configured; the DHCP router option is suppressed',
            );
        }

        $errors = [];
        if (filter_var($router, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = 'DHCP_DEFAULT_ROUTER is not a valid IPv4 address';
        } elseif (!$this->config->dhcpNetwork->contains($router)
            || $router === $this->config->dhcpNetwork->address
            || str_ends_with($router, '.255')) {
            $errors[] = "$router is not a usable host in {$this->config->dhcpNetwork->cidr}";
        } elseif ($router === $this->config->applianceIp) {
            $errors[] = 'the router address is the FenPing address';
        } elseif (!$interfaceReady) {
            $errors[] = 'router cannot be checked until IFACE is valid';
        } else {
            $route = $this->routeDevice($router);
            if ($route !== $interface) {
                $errors[] = $route === null ? "no route to $router" : "$router routes through $route instead of $interface";
            } else {
                try {
                    $result = $this->processes->run([
                        'arping', '-c', '2', '-w', '3', '-I', $interface, '-s', $this->config->applianceIp, $router,
                    ]);
                    if (!$result->successful()) {
                        $errors[] = "$router did not answer ARP on $interface";
                    }
                } catch (Throwable $error) {
                    $errors[] = 'ARP probe failed: ' . $error->getMessage();
                }
            }
        }
        return new DoctorCheck(
            'router',
            $errors === [],
            $errors === [] ? "$router is on-link and answered ARP on $interface" : implode('; ', $errors),
            $errors === [] ? '' : 'Set DHCP_DEFAULT_ROUTER to a reachable router in DHCP_NETWORK.',
        );
    }

    private function checkPool(): DoctorCheck
    {
        $beginText = trim($this->config->dhcpDynamicBegin);
        $endText = trim($this->config->dhcpDynamicEnd);
        $router = trim($this->config->dhcpDefaultRouter);
        $errors = [];
        if (!ctype_digit($beginText) || !ctype_digit($endText)) {
            $errors[] = 'DHCP pool bounds must be numeric host octets';
        } else {
            $begin = (int) $beginText;
            $end = (int) $endText;
            if ($begin < 1 || $begin > 254 || $end < 1 || $end > 254) {
                $errors[] = 'DHCP pool bounds must be from 1 to 254';
            } elseif ($begin > $end) {
                $errors[] = 'DHCP_DYNAMIC_BEGIN is greater than DHCP_DYNAMIC_END';
            } else {
                $reservedAddresses = ['FenPing' => $this->config->applianceIp];
                if ($router !== '') {
                    $reservedAddresses['router'] = $router;
                }
                foreach ($reservedAddresses as $label => $ip) {
                    if (!$this->config->dhcpNetwork->contains($ip)) {
                        continue;
                    }
                    $octet = self::hostOctet($ip);
                    if ($octet !== null && $octet >= $begin && $octet <= $end) {
                        $errors[] = "$label address $ip overlaps the dynamic pool";
                    }
                }
            }
        }
        $range = $this->config->dhcpNetwork->prefix() . ".$beginText-$endText";
        return new DoctorCheck(
            'dhcp-pool',
            $errors === [],
            $errors === []
                ? "$range excludes FenPing" . ($router === '' ? ' (no default router configured)' : ' and the router')
                : implode('; ', $errors),
            $errors === [] ? '' : 'Choose ordered pool bounds that exclude the appliance and router addresses.',
        );
    }

    private function checkPorts(string $interface, bool $interfaceReady, DoctorMode $mode): DoctorCheck
    {
        if (!$interfaceReady || filter_var($this->config->applianceIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return new DoctorCheck('ports', false, 'ports cannot be checked until IFACE and IP are valid', 'Fix the interface and IP configuration first.');
        }
        $errors = [];
        foreach ([
            ['DNS TCP', 'tcp', $this->config->applianceIp, 53, $interface, 'dnsmasq'],
            ['DNS UDP', 'udp', $this->config->applianceIp, 53, $interface, 'dnsmasq'],
            ['DHCP UDP', 'udp', '0.0.0.0', 67, $interface, 'dnsmasq'],
            ['TFTP UDP', 'udp', $this->config->applianceIp, 69, $interface, 'dnsmasq'],
            ['HTTP TCP', 'tcp', '0.0.0.0', 80, null, 'nginx'],
        ] as [$label, $protocol, $address, $port, $boundInterface, $expectedProcess]) {
            $error = $mode === DoctorMode::Runtime
                ? $this->system->listenerError($protocol, $address, $port, $boundInterface, $expectedProcess)
                : $this->system->bindError($protocol, $address, $port, $boundInterface);
            if ($error !== null) {
                $errors[] = $mode === DoctorMode::Runtime
                    ? "$label $address:$port is unhealthy: $error"
                    : "$label $address:$port is unavailable: $error";
            }
        }
        return new DoctorCheck(
            'ports',
            $errors === [],
            $errors === []
                ? ($mode === DoctorMode::Runtime
                    ? 'FenPing DNS, DHCP, TFTP, and HTTP listeners are active'
                    : 'DNS, DHCP, TFTP, and HTTP ports are available')
                : implode('; ', $errors),
            $errors === [] ? '' : ($mode === DoctorMode::Runtime
                ? 'Restart FenPing or inspect the process using the required listener.'
                : 'Stop the conflicting host service or move it away from FenPing’s required address and ports.'),
        );
    }

    private function checkStorage(): DoctorCheck
    {
        try {
            $errors = $this->system->storageErrors($this->config);
        } catch (Throwable $error) {
            $errors = [$error->getMessage()];
        }
        return new DoctorCheck(
            'storage',
            $errors === [],
            $errors === [] ? 'persistent paths support the required application and root writes' : implode('; ', $errors),
            $errors === [] ? '' : 'Correct bind-mount ownership, permissions, and filesystem capabilities without deleting live data.',
        );
    }

    private function checkDhcpServer(string $interface, bool $interfaceReady, DoctorMode $mode): DoctorCheck
    {
        if (!$interfaceReady) {
            return new DoctorCheck('dhcp-server', false, 'DHCP discovery cannot run until IFACE is valid', 'Fix IFACE before retrying startup.');
        }
        try {
            $result = $this->processes->run([
                'nmap', '-n', '-e', $interface,
                '--script', 'broadcast-dhcp-discover',
                '--script-args', 'broadcast-dhcp-discover.timeout=5s',
                '--script-timeout', '8s', '-oX', '-',
            ]);
            if (!$result->successful()) {
                throw new RuntimeException(trim($result->stderr) ?: 'nmap exited with status ' . $result->exitCode);
            }
            if (!str_contains($result->stdout, '<nmaprun')) {
                throw new RuntimeException('nmap did not return valid XML');
            }
            $offers = self::parseDhcpOffers($result->stdout);
            $ownResponse = false;
            if ($mode === DoctorMode::Runtime) {
                $offers = array_values(array_filter($offers, function (array $offer) use (&$ownResponse): bool {
                    if ($offer['server'] === $this->config->applianceIp) {
                        $ownResponse = true;
                        return false;
                    }
                    return true;
                }));
            }
            if ($offers !== []) {
                $servers = array_map(static function (array $offer): string {
                    $text = $offer['server'];
                    if ($offer['offered'] !== '') {
                        $text .= ' offered ' . $offer['offered'];
                    }
                    return $text;
                }, $offers);
                return new DoctorCheck(
                    'dhcp-server', false,
                    'another DHCP server responded: ' . implode(', ', $servers),
                    'Disable every other DHCP server on this LAN before starting authoritative DHCP.',
                );
            }
            return new DoctorCheck(
                'dhcp-server',
                true,
                $ownResponse
                    ? "FenPing DHCP responded and no other DHCP offer was observed on $interface"
                    : ($mode === DoctorMode::Runtime
                        ? "no other DHCP offer was observed on $interface"
                        : "no DHCP offer was observed on $interface"),
            );
        } catch (Throwable $error) {
            return new DoctorCheck(
                'dhcp-server', false, 'DHCP discovery failed: ' . $error->getMessage(),
                'Ensure the retained Nmap DHCP script can run as root with packet-capture access.',
            );
        }
    }

    /** @return list<string> */
    public static function parseInterfaceAddresses(string $output): array
    {
        preg_match_all('/\binet\s+(\d{1,3}(?:\.\d{1,3}){3})\/\d{1,2}\b/', $output, $matches);
        return array_values(array_unique(array_filter(
            $matches[1] ?? [],
            static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        )));
    }

    /** @return list<array{server: string, offered: string}> */
    public static function parseDhcpOffers(string $xml): array
    {
        $texts = [];
        if (preg_match_all('/<script\b([^>]*)>/i', $xml, $scripts)) {
            foreach ($scripts[1] as $attributes) {
                $id = self::xmlAttribute($attributes, 'id');
                if ($id !== 'broadcast-dhcp-discover') {
                    continue;
                }
                $output = self::xmlAttribute($attributes, 'output');
                if ($output !== null) {
                    $texts[] = $output;
                }
            }
        }
        if ($texts === [] && preg_match_all('/<elem\b[^>]*key=(?:"|\x27)Server Identifier(?:"|\x27)[^>]*>(.*?)<\/elem>/is', $xml, $servers)) {
            foreach ($servers[1] as $server) {
                $texts[] = 'Server Identifier: ' . html_entity_decode(strip_tags($server), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        $offers = [];
        foreach ($texts as $text) {
            if (preg_match('/(?:ERROR|Script execution failed)/i', $text)) {
                throw new RuntimeException(trim($text));
            }
            preg_match_all('/Server Identifier:\s*(\d{1,3}(?:\.\d{1,3}){3})/i', $text, $serverMatches);
            preg_match_all('/IP Offered:\s*(\d{1,3}(?:\.\d{1,3}){3})/i', $text, $offeredMatches);
            foreach ($serverMatches[1] ?? [] as $index => $server) {
                if (filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }
                $offered = (string) ($offeredMatches[1][$index] ?? '');
                if ($offered !== '' && filter_var($offered, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    $offered = '';
                }
                $offers[$server . '|' . $offered] = ['server' => $server, 'offered' => $offered];
            }
            if (($serverMatches[1] ?? []) === [] && preg_match('/DHCP Message Type:\s*DHCPOFFER/i', $text)) {
                $offered = (string) ($offeredMatches[1][0] ?? '');
                $offers['unknown|' . $offered] = ['server' => 'unknown server', 'offered' => $offered];
            }
        }
        return array_values($offers);
    }

    private function routeDevice(string $ip): ?string
    {
        try {
            $result = $this->processes->run(['ip', '-4', 'route', 'get', $ip]);
            if (!$result->successful()) {
                return null;
            }
            return self::parseRouteDevice($result->stdout);
        } catch (Throwable) {
            return null;
        }
    }

    private static function parseRouteDevice(string $output): ?string
    {
        return preg_match('/\bdev\s+(\S+)/', $output, $matches) === 1 ? $matches[1] : null;
    }

    private static function hostOctet(string $ip): ?int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        return (int) substr($ip, (int) strrpos($ip, '.') + 1);
    }

    private static function xmlAttribute(string $attributes, string $name): ?string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '=("|\x27)(.*?)\1/is', $attributes, $matches) !== 1) {
            return null;
        }
        return html_entity_decode($matches[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
