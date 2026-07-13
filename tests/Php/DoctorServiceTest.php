<?php

declare(strict_types=1);

namespace FenPing\Tests;

use DateTimeImmutable;
use FenPing\Cli\DoctorCommand;
use FenPing\Config\AppConfig;
use FenPing\Doctor\DoctorService;
use FenPing\Doctor\DoctorMode;
use FenPing\Doctor\NativeDoctorSystem;
use FenPing\Doctor\DoctorSystem;
use FenPing\Network\Ipv4Network;
use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;
use FenPing\Support\Clock;
use PHPUnit\Framework\TestCase;

final class DoctorServiceTest extends TestCase
{
    public function testJsonCliOutputUsesStableCheckShape(): void
    {
        ob_start();
        $exitCode = (new DoctorCommand($this->service()))->run(['--json']);
        $output = ob_get_clean();
        self::assertSame(0, $exitCode);
        self::assertIsString($output);
        $document = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ok', $document['status']);
        self::assertSame('interface', $document['checks'][0]['id']);
        self::assertSame('pass', $document['checks'][0]['status']);
    }

    public function testHealthyStartupPassesEveryCheck(): void
    {
        $service = $this->service();
        $report = $service->inspect();

        self::assertTrue($report->passed());
        self::assertSame(
            ['interface', 'subnet', 'router', 'dhcp-pool', 'ports', 'storage', 'dhcp-server'],
            array_map(static fn($check): string => $check->id, $report->checks),
        );
        self::assertSame('2026-07-13T12:00:00+00:00', $report->checkedAt);
    }

    public function testInterfaceFailureAggregatesDependentAndStorageFailures(): void
    {
        $system = new DoctorTestSystem();
        $system->exists = false;
        $system->storage = ['database directory: application-worker write failed'];
        $report = $this->service(system: $system)->inspect();

        self::assertFalse($report->passed());
        self::assertSame(
            ['interface', 'subnet', 'router', 'ports', 'storage', 'dhcp-server'],
            $this->failedIds($report->checks),
        );
        self::assertStringContainsString('does not exist', $report->checks[0]->message);
        self::assertStringContainsString('application-worker write failed', $report->checks[5]->message);
    }

    public function testRouterReachabilityAndPoolEndpointOverlapAreBlocking(): void
    {
        $processes = new DoctorTestProcessRunner();
        $processes->arpSuccessful = false;
        $config = $this->config(begin: '1', end: '200');
        $report = $this->service($config, $processes)->inspect();

        self::assertSame(['router', 'dhcp-pool'], $this->failedIds($report->checks));
        self::assertStringContainsString('did not answer ARP', $report->checks[2]->message);
        self::assertStringContainsString('FenPing address', $report->checks[3]->message);
        self::assertStringContainsString('router address', $report->checks[3]->message);
    }

    public function testOmittedRouterSkipsReachabilityAndSuppressesTheDhcpOption(): void
    {
        $processes = new DoctorTestProcessRunner();
        $processes->arpSuccessful = false;
        $report = $this->service($this->config(router: ''), $processes)->inspect();

        self::assertTrue($report->passed());
        self::assertStringContainsString('router option is suppressed', $report->checks[2]->message);
        self::assertStringContainsString('no default router configured', $report->checks[3]->message);
    }

    public function testPortAndDiskErrorsAreReportedTogether(): void
    {
        $system = new DoctorTestSystem();
        $system->bindErrors['udp|0.0.0.0|67|eth0'] = 'Address already in use';
        $system->storage = ['state directory: atomic rename failed'];
        $report = $this->service(system: $system)->inspect();

        self::assertSame(['ports', 'storage'], $this->failedIds($report->checks));
        self::assertStringContainsString('DHCP UDP', $report->checks[4]->message);
        self::assertStringContainsString('atomic rename failed', $report->checks[5]->message);
    }

    public function testAnyDhcpOfferBlocksStartupAndReportsAllServers(): void
    {
        $processes = new DoctorTestProcessRunner();
        $processes->nmapXml = <<<'XML'
<?xml version="1.0"?>
<nmaprun><prescript><script id="broadcast-dhcp-discover" output="Response 1 of 2:&#xa;  IP Offered: 192.0.2.40&#xa;  DHCP Message Type: DHCPOFFER&#xa;  Server Identifier: 192.0.2.1&#xa;Response 2 of 2:&#xa;  IP Offered: 192.0.2.41&#xa;  DHCP Message Type: DHCPOFFER&#xa;  Server Identifier: 192.0.2.2"/></prescript></nmaprun>
XML;
        $report = $this->service(processes: $processes)->inspect();

        self::assertSame(['dhcp-server'], $this->failedIds($report->checks));
        self::assertStringContainsString('192.0.2.1 offered 192.0.2.40', $report->checks[6]->message);
        self::assertStringContainsString('192.0.2.2 offered 192.0.2.41', $report->checks[6]->message);
    }

    public function testRuntimeModeUsesOwnedListenersAndIgnoresOnlyFenPingDhcp(): void
    {
        $processes = new DoctorTestProcessRunner();
        $processes->nmapXml = <<<'XML'
<?xml version="1.0"?>
<nmaprun><prescript><script id="broadcast-dhcp-discover" output="DHCP Message Type: DHCPOFFER&#xa;IP Offered: 192.0.2.210&#xa;Server Identifier: 192.0.2.100"/></prescript></nmaprun>
XML;
        $system = new DoctorTestSystem();
        $report = $this->service(processes: $processes, system: $system)->inspect(DoctorMode::Runtime);

        self::assertTrue($report->passed());
        self::assertCount(5, $system->listenerCalls);
        self::assertSame([], $system->bindCalls);
        self::assertStringContainsString('listeners are active', $report->checks[4]->message);
        self::assertStringContainsString('FenPing DHCP responded', $report->checks[6]->message);
    }

    public function testRuntimeModeStillBlocksAnotherDhcpServer(): void
    {
        $processes = new DoctorTestProcessRunner();
        $processes->nmapXml = <<<'XML'
<?xml version="1.0"?>
<nmaprun><prescript><script id="broadcast-dhcp-discover" output="Response 1 of 2:&#xa;IP Offered: 192.0.2.210&#xa;Server Identifier: 192.0.2.100&#xa;Response 2 of 2:&#xa;IP Offered: 192.0.2.211&#xa;Server Identifier: 192.0.2.2"/></prescript></nmaprun>
XML;
        $report = $this->service(processes: $processes)->inspect(DoctorMode::Runtime);

        self::assertSame(['dhcp-server'], $this->failedIds($report->checks));
        self::assertStringNotContainsString('192.0.2.100', $report->checks[6]->message);
        self::assertStringContainsString('192.0.2.2 offered 192.0.2.211', $report->checks[6]->message);
    }

    public function testDhcpProbeFailureIsBlocking(): void
    {
        $processes = new DoctorTestProcessRunner();
        $processes->nmapSuccessful = false;
        $report = $this->service(processes: $processes)->inspect();

        self::assertSame(['dhcp-server'], $this->failedIds($report->checks));
        self::assertStringContainsString('packet capture unavailable', $report->checks[6]->message);
    }

    public function testNativeTcpPortProbeRejectsListenerButAllowsTimeWaitSocket(): void
    {
        $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $listener);
        self::assertTrue(socket_set_option($listener, SOL_SOCKET, SO_REUSEADDR, 1));
        self::assertTrue(socket_bind($listener, '127.0.0.1', 0));
        self::assertTrue(socket_getsockname($listener, $address, $port));
        self::assertTrue(socket_listen($listener, 1));

        $system = new NativeDoctorSystem(new DoctorTestProcessRunner());
        self::assertNotNull($system->bindError('tcp', '127.0.0.1', $port));

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $client);
        self::assertTrue(socket_connect($client, '127.0.0.1', $port));
        $accepted = socket_accept($listener);
        self::assertInstanceOf(\Socket::class, $accepted);

        socket_close($accepted);
        $buffer = '';
        self::assertSame(0, socket_recv($client, $buffer, 1, 0));
        socket_close($client);
        socket_close($listener);

        self::assertNull(
            $system->bindError('tcp', '127.0.0.1', $port),
            'TCP TIME_WAIT must not be mistaken for an active listener',
        );
    }

    public function testNativeRuntimeListenerProbeRequiresTheExpectedOwner(): void
    {
        $processes = new DoctorTestProcessRunner();
        $processes->ssOutput = <<<'SS'
LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("nginx",pid=10,fd=6))
UNCONN 0 0 192.0.2.100%eth0:53 0.0.0.0:* users:(("dnsmasq",pid=11,fd=4))
UNCONN 0 0 0.0.0.0%eth0:67 0.0.0.0:*
SS;
        $system = new NativeDoctorSystem($processes);

        self::assertNull($system->listenerError('tcp', '0.0.0.0', 80, null, 'nginx'));
        self::assertNull($system->listenerError('udp', '192.0.2.100', 53, 'eth0', 'dnsmasq'));
        self::assertNull($system->listenerError('udp', '0.0.0.0', 67, 'eth0', 'dnsmasq'));
        self::assertStringContainsString(
            'instead of dnsmasq',
            (string) $system->listenerError('tcp', '0.0.0.0', 80, null, 'dnsmasq'),
        );
        self::assertSame(
            'dnsmasq is not listening',
            $system->listenerError('udp', '192.0.2.100', 69, 'eth0', 'dnsmasq'),
        );
    }

    public function testParsersRejectNoiseAndDeduplicateOffers(): void
    {
        self::assertSame(
            ['192.0.2.100', '198.51.100.2'],
            DoctorService::parseInterfaceAddresses(
                "2: eth0 inet 192.0.2.100/24 scope global eth0\n2: eth0 inet 198.51.100.2/24 scope global eth0\n",
            ),
        );
        self::assertSame([], DoctorService::parseDhcpOffers('<?xml version="1.0"?><nmaprun><prescript/></nmaprun>'));

        $xml = '<nmaprun><prescript><script id="broadcast-dhcp-discover" '
            . 'output="DHCP Message Type: DHCPOFFER&#xa;Server Identifier: 192.0.2.1"/></prescript></nmaprun>';
        self::assertSame(
            [['server' => '192.0.2.1', 'offered' => '']],
            DoctorService::parseDhcpOffers($xml),
        );
    }

    private function service(
        ?AppConfig $config = null,
        ?DoctorTestProcessRunner $processes = null,
        ?DoctorTestSystem $system = null,
    ): DoctorService {
        return new DoctorService(
            $config ?? $this->config(),
            $processes ?? new DoctorTestProcessRunner(),
            $system ?? new DoctorTestSystem(),
            new DoctorTestClock(),
        );
    }

    private function config(string $begin = '200', string $end = '250', string $router = '192.0.2.1'): AppConfig
    {
        return new AppConfig(
            projectDir: dirname(__DIR__, 2),
            databasePath: '/tmp/fenping-doctor/database.sqlite3',
            dhcpNetwork: Ipv4Network::from24('192.0.2.0/24'),
            extraNetworks: [],
            interface: 'eth0',
            applianceIp: '192.0.2.100',
            dhcpDynamicBegin: $begin,
            dhcpDynamicEnd: $end,
            password: '',
            secret: 'test',
            discordWebhookUrl: '',
            dataDir: '/tmp/fenping-doctor',
            dhcpDefaultRouter: $router,
        );
    }

    private function failedIds(array $checks): array
    {
        return array_values(array_map(
            static fn($check): string => $check->id,
            array_filter($checks, static fn($check): bool => !$check->passed),
        ));
    }
}

final class DoctorTestSystem implements DoctorSystem
{
    public bool $exists = true;
    public bool $up = true;
    public array $bindErrors = [];
    public array $bindCalls = [];
    public array $listenerErrors = [];
    public array $listenerCalls = [];
    public array $storage = [];

    public function interfaceExists(string $interface): bool { return $this->exists; }
    public function interfaceUp(string $interface): bool { return $this->up; }

    public function bindError(string $protocol, string $address, int $port, ?string $interface = null): ?string
    {
        $key = implode('|', [$protocol, $address, $port, $interface ?? '']);
        $this->bindCalls[] = $key;
        return $this->bindErrors[$key] ?? null;
    }

    public function listenerError(
        string $protocol,
        string $address,
        int $port,
        ?string $interface,
        string $expectedProcess,
    ): ?string {
        $key = implode('|', [$protocol, $address, $port, $interface ?? '', $expectedProcess]);
        $this->listenerCalls[] = $key;
        return $this->listenerErrors[$key] ?? null;
    }

    public function storageErrors(AppConfig $config): array { return $this->storage; }
}

final class DoctorTestProcessRunner implements ProcessRunner
{
    public bool $arpSuccessful = true;
    public bool $nmapSuccessful = true;
    public string $routeDevice = 'eth0';
    public string $nmapXml = '<?xml version="1.0"?><nmaprun><prescript/></nmaprun>';
    public string $ssOutput = '';

    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        if ($command[0] === 'ip' && in_array('address', $command, true)) {
            return new ProcessResult(0, "2: eth0 inet 192.0.2.100/24 scope global eth0\n");
        }
        if ($command[0] === 'ip' && in_array('route', $command, true)) {
            return new ProcessResult(0, $command[array_key_last($command)] . ' dev ' . $this->routeDevice . " src 192.0.2.100\n");
        }
        if ($command[0] === 'arping') {
            return new ProcessResult($this->arpSuccessful ? 0 : 1, '', $this->arpSuccessful ? '' : 'no response');
        }
        if ($command[0] === 'nmap') {
            return new ProcessResult($this->nmapSuccessful ? 0 : 1, $this->nmapXml, $this->nmapSuccessful ? '' : 'packet capture unavailable');
        }
        if ($command[0] === 'ss') {
            return new ProcessResult(0, $this->ssOutput);
        }
        if ($command[0] === 'pidof') {
            return new ProcessResult(in_array($command[1] ?? '', ['dnsmasq', 'nginx'], true) ? 0 : 1, "11\n");
        }
        return new ProcessResult(1, '', 'unexpected command: ' . implode(' ', $command));
    }
}

final readonly class DoctorTestClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-13T12:00:00+00:00'); }
}
