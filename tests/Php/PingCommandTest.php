<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Cli\PingCommand;
use FenPing\Ipam\IpConflictScanner;
use FenPing\Network\Ipv4Network;
use FenPing\Network\NetworkManager;
use FenPing\Network\RouteDetector;
use FenPing\Ping\PingScanner;
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

    private function command(IpConflictScanner $scanner): PingCommand
    {
        $config = $this->app()->config();
        return new PingCommand(
            $config,
            new NetworkManager($config, new RouteDetector(new PingCommandUnusedProcessRunner())),
            new PingScanner($config),
            $this->app()->pingRepository(),
            $this->app()->notifications(),
            $this->app()->discord(),
            $scanner,
            $this->app()->ipConflictService(),
        );
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
