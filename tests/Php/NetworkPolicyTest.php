<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Config\AppConfig;
use FenPing\Network\Ipv4Network;
use FenPing\Network\NetworkManager;
use FenPing\Network\RouteDetector;
use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NetworkPolicyTest extends TestCase
{
    public function testCanonical24Validation(): void
    {
        $network = Ipv4Network::from24('10.68.69.0/24');
        self::assertSame('10.68.69', $network->prefix());
        self::assertTrue($network->contains('10.68.69.254'));
        self::assertFalse($network->contains('10.68.70.1'));

        foreach (['10.68.69', '10.68.69.1/24', '10.68.69.0/16', '10.068.69.0/24'] as $invalid) {
            try {
                Ipv4Network::from24($invalid);
                self::fail("accepted invalid network $invalid");
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testEnvironmentRequiresNewVariablesAndRejectsDuplicates(): void
    {
        $previousDhcp = getenv('DHCP_NETWORK');
        $previousExtra = getenv('EXTRA_NETWORKS');
        $previousLegacy = getenv('NETWORK');
        try {
            putenv('DHCP_NETWORK');
            putenv('NETWORK=192.0.2');
            $this->expectException(InvalidArgumentException::class);
            AppConfig::fromEnvironment(dirname(__DIR__, 2));
        } finally {
            $this->restoreEnv('DHCP_NETWORK', $previousDhcp);
            $this->restoreEnv('EXTRA_NETWORKS', $previousExtra);
            $this->restoreEnv('NETWORK', $previousLegacy);
        }
    }

    public function testDuplicateExtraNetworkIsRejected(): void
    {
        $previousDhcp = getenv('DHCP_NETWORK');
        $previousExtra = getenv('EXTRA_NETWORKS');
        try {
            putenv('DHCP_NETWORK=192.0.2.0/24');
            putenv('EXTRA_NETWORKS=198.51.100.0/24, 198.51.100.0/24');
            $this->expectException(InvalidArgumentException::class);
            AppConfig::fromEnvironment(dirname(__DIR__, 2));
        } finally {
            $this->restoreEnv('DHCP_NETWORK', $previousDhcp);
            $this->restoreEnv('EXTRA_NETWORKS', $previousExtra);
        }
    }

    public function testDownRetentionDefaultsToSevenDaysAndRejectsInvalidValues(): void
    {
        $previous = getenv('INVENTORY_DOWN_RETENTION_DAYS');
        try {
            putenv('INVENTORY_DOWN_RETENTION_DAYS');
            self::assertSame(7, AppConfig::fromEnvironment(dirname(__DIR__, 2))->inventoryDownRetentionDays);

            foreach (['-1', '1.5', 'seven'] as $invalid) {
                putenv('INVENTORY_DOWN_RETENTION_DAYS=' . $invalid);
                try {
                    AppConfig::fromEnvironment(dirname(__DIR__, 2));
                    self::fail("accepted invalid retention value $invalid");
                } catch (InvalidArgumentException) {
                }
            }
        } finally {
            $this->restoreEnv('INVENTORY_DOWN_RETENTION_DAYS', $previous);
        }
    }

    public function testRouteDetectionRequiresAnExplicitCoveringRoute(): void
    {
        $network = Ipv4Network::from24('192.168.20.0/24');
        self::assertTrue(RouteDetector::outputCovers("192.168.20.0/24 via 10.0.0.1 dev eth0\n", $network));
        self::assertTrue(RouteDetector::outputCovers("192.168.0.0/16 via 10.0.0.1 dev eth0\n", $network));
        self::assertFalse(RouteDetector::outputCovers("default via 10.0.0.1 dev eth0\n", $network));
        self::assertFalse(RouteDetector::outputCovers("192.168.20.0/25 via 10.0.0.1 dev eth0\n", $network));
        self::assertFalse(RouteDetector::outputCovers("blackhole 192.168.20.0/24\n", $network));
        self::assertFalse(RouteDetector::outputCovers("unreachable 192.168.20.0/24\n", $network));
        self::assertFalse(RouteDetector::outputCovers("192.168.21.0/24 via 10.0.0.1 dev eth0\n", $network));
    }

    public function testPingAndInventoryRotateIndependently(): void
    {
        $dir = sys_get_temp_dir() . '/fenping-network-' . bin2hex(random_bytes(4));
        $config = new AppConfig(
            projectDir: dirname(__DIR__, 2),
            databasePath: $dir . '/database.sqlite3',
            dhcpNetwork: Ipv4Network::from24('192.0.2.0/24'),
            extraNetworks: [Ipv4Network::from24('198.51.100.0/24'), Ipv4Network::from24('203.0.113.0/24')],
            interface: 'eth0', applianceIp: '192.0.2.100', dhcpDynamicBegin: '200', dhcpDynamicEnd: '250',
            password: '', secret: 'test', discordWebhookUrl: '', dataDir: $dir,
        );
        $routes = new RouteDetector(new FixedProcessRunner("198.51.100.0/24 via 192.0.2.1 dev eth0\n"));
        $manager = new NetworkManager($config, $routes);

        self::assertSame('192.0.2.0/24', $manager->nextScheduled('inventory')->cidr);
        self::assertSame('198.51.100.0/24', $manager->nextScheduled('inventory')->cidr);
        self::assertSame('192.0.2.0/24', $manager->nextScheduled('ping')->cidr);
        self::assertFalse($manager->descriptors()[2]['routed']);
        self::assertTrue($manager->descriptors()[2]['selectable']);
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
}

final readonly class FixedProcessRunner implements ProcessRunner
{
    public function __construct(private string $stdout) {}

    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        return new ProcessResult(0, $this->stdout);
    }
}
