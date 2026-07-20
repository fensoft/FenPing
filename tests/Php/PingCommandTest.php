<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Cli\PingCommand;
use FenPing\Ipam\IpConflictScanner;
use FenPing\Network\Ipv4Network;
use FenPing\Network\NetworkManager;
use FenPing\Network\RouteDetector;
use FenPing\Ping\PingScanner;
use FenPing\Ping\PingScannerGateway;
use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;

final class PingCommandTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testSuccessfulConflictScanContinuesThroughPingPersistence(): void
    {
        $scanner = new PingCommandConflictScanner([
            "successful" => true,
            "transitions" => [],
            "error" => null,
        ]);

        self::assertSame(0, $this->command($scanner)->run(["100"]));
        self::assertSame("192.0.2.0/24", $scanner->network?->cidr);
        self::assertSame(
            ["ip" => "192.0.2.100", "status" => "Up"],
            $this->storedPing(),
        );
        self::assertSame(0, (int) $this->app()->database()->connection()
            ->query('SELECT COUNT(*) FROM network_anomaly_monitors')->fetchColumn());
    }

    public function testFailedConflictScanRemainsNonfatalAndPersistsPing(): void
    {
        $scanner = new PingCommandConflictScanner([
            "successful" => false,
            "transitions" => [],
            "error" => "capture failed",
        ]);

        self::assertSame(0, $this->command($scanner)->run(["100"]));
        self::assertSame("192.0.2.0/24", $scanner->network?->cidr);
        self::assertSame(
            ["ip" => "192.0.2.100", "status" => "Up"],
            $this->storedPing(),
        );
    }

    public function testPreviouslyUpHostRecoversOnRetryWithoutDownTransition(): void
    {
        $this->app()->pingRepository()->save([
            ['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:64', 'status' => 'Up'],
        ]);
        $scanner = new PingCommandScriptedScanner([
            [['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:64', 'status' => 'Down']],
            [['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:64', 'status' => 'Up']],
        ]);

        $this->expectOutputString("192.0.2.100 Up\n");
        self::assertSame(0, $this->command($this->successfulConflictScanner(), $scanner)->run(['100']));
        self::assertCount(2, $scanner->calls);
        self::assertSame(['192.0.2.100'], $scanner->calls[1]['ips']);
        self::assertSame(
            ['ip' => '192.0.2.100', 'status' => 'Up'],
            $this->storedPing(),
        );
        self::assertSame(1, $this->statusHistoryCount('192.0.2.100'));
    }

    public function testPreviouslyUpHostIsPersistedAfterThreeFailedRetries(): void
    {
        $this->app()->pingRepository()->save([
            ['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:64', 'status' => 'Up'],
        ]);
        $scanner = new PingCommandScriptedScanner([
            [['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:64', 'status' => 'Down']],
            [['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:64', 'status' => 'arp']],
            [['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:64', 'status' => 'arp-down']],
            [['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:65', 'status' => 'Down']],
        ]);

        $this->expectOutputString("192.0.2.100 Down\n");
        self::assertSame(0, $this->command($this->successfulConflictScanner(), $scanner)->run(['100']));
        self::assertCount(4, $scanner->calls);
        self::assertSame(
            ['ip' => '192.0.2.100', 'mac' => '02:00:00:00:00:65', 'status' => 'Down'],
            $this->storedPingWithMac(),
        );
        self::assertSame(['Up', 'Down'], $this->statusHistory('192.0.2.100'));
    }

    public function testRetryCandidatesAreBatchedAndRecoveredHostsAreRemoved(): void
    {
        $this->app()->pingRepository()->save([
            ['ip' => '192.0.2.10', 'mac' => '02:00:00:00:00:10', 'status' => 'Up'],
            ['ip' => '192.0.2.20', 'mac' => '02:00:00:00:00:20', 'status' => 'Up'],
        ]);
        $initial = $this->networkHosts();
        $initial[9]['status'] = 'Down';
        $initial[19]['status'] = 'Down';
        $scanner = new PingCommandScriptedScanner([
            $initial,
            [
                ['ip' => '192.0.2.10', 'mac' => '02:00:00:00:00:10', 'status' => 'Up'],
                ['ip' => '192.0.2.20', 'mac' => '02:00:00:00:00:20', 'status' => 'Down'],
            ],
            [
                ['ip' => '192.0.2.20', 'mac' => '02:00:00:00:00:20', 'status' => 'Up'],
            ],
        ]);

        self::assertSame(0, $this->command($this->successfulConflictScanner(), $scanner)->run([
            '--network', '192.0.2.0/24',
        ]));
        self::assertCount(3, $scanner->calls);
        self::assertSame(['192.0.2.10', '192.0.2.20'], $scanner->calls[1]['ips']);
        self::assertSame(['192.0.2.20'], $scanner->calls[2]['ips']);
        self::assertSame(1, $this->statusHistoryCount('192.0.2.10'));
        self::assertSame(1, $this->statusHistoryCount('192.0.2.20'));
        self::assertSame(1, (int) $this->app()->database()->connection()
            ->query('SELECT COUNT(*) FROM network_anomaly_monitors')->fetchColumn());
    }

    public function testOnlyPreviouslyUpHostsAreEligibleForRetries(): void
    {
        $this->app()->pingRepository()->save([
            ['ip' => '192.0.2.10', 'mac' => '', 'status' => 'Down'],
            ['ip' => '192.0.2.20', 'mac' => '02:00:00:00:00:20', 'status' => 'arp'],
            ['ip' => '192.0.2.40', 'mac' => '02:00:00:00:00:40', 'status' => 'Up'],
        ]);
        $initial = $this->networkHosts();
        $initial[9]['status'] = 'Down';
        $initial[19]['status'] = 'arp-down';
        $initial[29]['status'] = 'Down';
        $scanner = new PingCommandScriptedScanner([$initial]);

        self::assertSame(0, $this->command($this->successfulConflictScanner(), $scanner)->run([
            '--network', '192.0.2.0/24',
        ]));
        self::assertCount(1, $scanner->calls);
    }

    private function command(
        IpConflictScanner $conflictScanner,
        ?PingScannerGateway $pingScanner = null,
    ): PingCommand
    {
        $config = $this->app()->config();
        return new PingCommand(
            $config,
            new NetworkManager($config, new RouteDetector(new PingCommandUnusedProcessRunner())),
            $pingScanner ?? new PingScanner($config),
            $this->app()->pingRepository(),
            $this->app()->notifications(),
            $this->app()->discord(),
            $conflictScanner,
            $this->app()->ipConflictService(),
            $this->app()->anomalies(),
        );
    }

    private function successfulConflictScanner(): IpConflictScanner
    {
        return new PingCommandConflictScanner([
            'successful' => true,
            'transitions' => [],
            'error' => null,
        ]);
    }

    private function networkHosts(): array
    {
        $hosts = [];
        for ($host = 1; $host <= 254; $host++) {
            $hosts[] = [
                'ip' => "192.0.2.$host",
                'mac' => '',
                'status' => 'Up',
            ];
        }
        return $hosts;
    }

    private function storedPing(): array
    {
        $statement = $this->app()->database()->connection()->prepare(
            "SELECT ip, status FROM ping WHERE ip=:ip",
        );
        $statement->execute(["ip" => "192.0.2.100"]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        return ["ip" => $row["ip"], "status" => $row["status"]];
    }

    private function storedPingWithMac(): array
    {
        $statement = $this->app()->database()->connection()->prepare(
            'SELECT ip, mac, status FROM ping WHERE ip=:ip',
        );
        $statement->execute(['ip' => '192.0.2.100']);
        $row = $statement->fetch();
        self::assertIsArray($row);
        return ['ip' => $row['ip'], 'mac' => $row['mac'], 'status' => $row['status']];
    }

    private function statusHistoryCount(string $ip): int
    {
        return count($this->statusHistory($ip));
    }

    private function statusHistory(string $ip): array
    {
        $statement = $this->app()->database()->connection()->prepare(
            'SELECT status FROM stats WHERE ip=:ip ORDER BY id',
        );
        $statement->execute(['ip' => $ip]);
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }
}

final class PingCommandConflictScanner implements IpConflictScanner
{
    public ?Ipv4Network $network = null;

    public function __construct(private readonly array $result) {}

    public function scan(Ipv4Network $network): array
    {
        $this->network = $network;
        return $this->result;
    }
}

final class PingCommandScriptedScanner implements PingScannerGateway
{
    public array $calls = [];

    public function __construct(private array $responses) {}

    public function scan(array $ips, array $localIps = []): array
    {
        $this->calls[] = [
            'ips' => array_values($ips),
            'local_ips' => array_values($localIps),
        ];
        if ($this->responses === []) {
            throw new \LogicException('unexpected ping scan');
        }
        return array_shift($this->responses);
    }
}

final readonly class PingCommandUnusedProcessRunner implements ProcessRunner
{
    public function run(
        array $command,
        array $environment = [],
        ?string $stdinFile = null,
        ?string $stdoutFile = null,
    ): ProcessResult {
        throw new \LogicException("route inspection is not expected");
    }
}
