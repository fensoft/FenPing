<?php

declare(strict_types=1);

namespace FenPing\Tests;

use DateTimeImmutable;
use FenPing\Api\Request;
use FenPing\Ipam\IpConflictDetector;
use FenPing\Network\Ipv4Network;
use FenPing\Network\RouteDetector;
use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;
use FenPing\Support\Clock;

final class IpConflictTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testParserRequiresDistinctValidMacsAndSeedsTheAppliance(): void
    {
        $network = $this->app()->config()->dhcpNetwork;
        $conflicts = $this->app()->ipConflictDetector()->parse(implode("\n", [
            '192.0.2.10 02:00:00:00:00:10',
            '192.0.2.10 02:00:00:00:00:10 DUP:2',
            '192.0.2.20 02:00:00:00:00:20',
            '192.0.2.20 02:00:00:00:00:21',
            '192.0.2.30 01:00:5e:00:00:01',
            '192.0.2.30 00:00:00:00:00:00',
            '192.0.2.0 02:00:00:00:00:01',
            '198.51.100.20 02:00:00:00:00:22',
            'malformed output',
            '192.0.2.100 02:00:00:00:01:00',
        ]), $network, '192.0.2.100', '02:00:00:00:00:64');

        self::assertSame(['192.0.2.20', '192.0.2.100'], array_keys($conflicts));
        self::assertSame([
            '02:00:00:00:00:20',
            '02:00:00:00:00:21',
        ], array_keys($conflicts['192.0.2.20']));
        self::assertCount(2, $conflicts['192.0.2.100']);
    }

    public function testConflictEpisodeOpensUpdatesResolvesAndCanRecur(): void
    {
        $repository = $this->app()->ipConflicts();
        $network = $this->app()->config()->dhcpNetwork;
        $startedAt = new DateTimeImmutable('-45 minutes UTC');
        $observations = ['192.0.2.40' => [
            '02:00:00:00:00:40' => true,
            '02:00:00:00:00:41' => true,
        ]];

        $opened = $repository->reconcile($network, $observations, $startedAt);
        self::assertSame('detected', $opened[0]['type']);
        $firstId = $opened[0]['id'];
        self::assertCount(1, $this->app()->discord()->discordIpConflictPayloads(
            $this->app()->ipConflictService()->transitionDetails($opened),
        ));

        $updatedObservations = ['192.0.2.40' => [
            '02:00:00:00:00:40' => true,
            '02:00:00:00:00:42' => true,
        ]];
        $continued = $repository->reconcile($network, $updatedObservations, $startedAt->modify('+15 minutes'));
        self::assertSame([], $continued);
        self::assertSame([], $this->app()->discord()->discordIpConflictPayloads(
            $this->app()->ipConflictService()->transitionDetails($continued),
        ));
        self::assertCount(1, $repository->active());
        self::assertSame([
            '02:00:00:00:00:40',
            '02:00:00:00:00:42',
        ], array_column($repository->active()[0]['devices'], 'mac'));

        $resolved = $repository->reconcile($network, [], $startedAt->modify('+30 minutes'));
        self::assertSame([['id' => $firstId, 'type' => 'resolved']], $resolved);
        $resolvedPayloads = $this->app()->discord()->discordIpConflictPayloads(
            $this->app()->ipConflictService()->transitionDetails($resolved),
        );
        self::assertCount(1, $resolvedPayloads);
        self::assertStringContainsString('IP conflict resolved', $resolvedPayloads[0]['embeds'][0]['title']);
        self::assertSame([], $repository->active());

        $reopened = $repository->reconcile($network, $observations, $startedAt->modify('+45 minutes'));
        self::assertSame('detected', $reopened[0]['type']);
        self::assertNotSame($firstId, $reopened[0]['id']);
        self::assertCount(3, $repository->recent(168));
    }

    public function testFailedScanPreservesActiveConflictAndMarksMonitorDegraded(): void
    {
        $repository = $this->app()->ipConflicts();
        $network = $this->app()->config()->dhcpNetwork;
        $repository->reconcile($network, ['192.0.2.50' => [
            '02:00:00:00:00:50' => true,
            '02:00:00:00:00:51' => true,
        ]], new DateTimeImmutable('2026-07-13 11:00:00 UTC'));

        $detector = new IpConflictDetector(
            $this->app()->config(),
            new StubProcessRunner(new ProcessResult(2, '', 'capture failed')),
            new RouteDetector(new StubProcessRunner(new ProcessResult(2, '', 'route lookup failed'))),
            $repository,
            new FixedClock(new DateTimeImmutable('2026-07-13 11:15:00 UTC')),
        );
        $result = $detector->scan($network);

        self::assertFalse($result['successful']);
        self::assertCount(1, $repository->active());
        self::assertSame('degraded', $repository->monitors()[0]['status']);
        $status = $this->app()->ipConflictService()->status($network->cidr);
        self::assertSame('degraded', $status['status']);
        self::assertCount(1, $status['conflicts']);
        self::assertSame(
            'degraded', $this->app()->health()->status()['ip_conflict_detection']['status'],
        );
    }

    public function testScanUsesTheDirectRouteInterfaceForDockerNetworks(): void
    {
        $network = Ipv4Network::from24('172.17.0.0/24', 'test network');
        $processes = new RouteAwareConflictProcessRunner(new ProcessResult(0, implode("\n", [
            '172.17.0.20 02:00:00:00:00:20',
            '172.17.0.20 02:00:00:00:00:21',
        ]), ''));
        $detector = new IpConflictDetector(
            $this->app()->config(),
            $processes,
            new RouteDetector($processes),
            $this->app()->ipConflicts(),
            new FixedClock(new DateTimeImmutable('2026-07-13 11:15:00 UTC')),
        );

        $result = $detector->scan($network);

        self::assertTrue($result['successful']);
        self::assertSame([
            ['ip', '-4', 'route', 'show'],
            [
                '/usr/bin/arp-scan',
                '--interface=docker0',
                '--quiet',
                '--plain',
                '--retry=2',
                '172.17.0.1-172.17.0.254',
            ],
        ], $processes->commands);
        self::assertSame('172.17.0.20', $this->app()->ipConflicts()->active($network->cidr)[0]['ip']);
    }

    public function testScanRejectsNetworksReachedThroughAGateway(): void
    {
        $network = Ipv4Network::from24('198.51.100.0/24', 'test network');
        $processes = new RoutedConflictProcessRunner();
        $detector = new IpConflictDetector(
            $this->app()->config(),
            $processes,
            new RouteDetector($processes),
            $this->app()->ipConflicts(),
            new FixedClock(new DateTimeImmutable('2026-07-13 11:15:00 UTC')),
        );

        $result = $detector->scan($network);

        self::assertFalse($result['successful']);
        self::assertSame('IP conflict detection requires a directly connected network: 198.51.100.0/24', $result['error']);
        self::assertSame([['ip', '-4', 'route', 'show']], $processes->commands);
    }

    public function testApiNotificationsDiscordAndBackupsExposeConflictData(): void
    {
        $network = $this->app()->config()->dhcpNetwork;
        $importantHostId = $this->app()->hosts()->create('192.0.2.60', '02:00:00:00:00:60');
        $this->app()->database()->connection()->exec("UPDATE ips SET important=1 WHERE id=$importantHostId");
        $opened = $this->app()->ipConflicts()->reconcile($network, ['192.0.2.60' => [
            '02:00:00:00:00:60' => true,
            '02:00:00:00:00:61' => true,
        ]], new DateTimeImmutable());

        $response = $this->app()->api()->handle(new Request(
            'GET', '/api/ipam/conflicts', [], [], [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/ipam/conflicts'], [],
        ));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('192.0.2.60', $body['conflicts'][0]['ip']);
        self::assertCount(2, $body['conflicts'][0]['devices']);

        $ipam = $this->app()->ipam()->summary();
        self::assertCount(1, $ipam['conflicts']);
        $notifications = $this->app()->notifications()->recent();
        self::assertSame(1, $notifications['summary']['conflict_total']);
        self::assertSame('detected', $notifications['conflict_changes'][0]['type']);
        self::assertSame(1, $notifications['conflict_changes'][0]['important']);
        self::assertSame(1, $notifications['conflict_changes'][0]['devices'][0]['important']);

        $details = $this->app()->ipConflictService()->transitionDetails($opened);
        $payloads = $this->app()->discord()->discordIpConflictPayloads($details);
        self::assertCount(1, $payloads);
        self::assertStringContainsString('Possible IP conflict', $payloads[0]['embeds'][0]['title']);
        self::assertContains('ip_conflicts', $this->app()->backupTables()->backupTableNames());
        self::assertContains('ip_conflict_devices', $this->app()->backupTables()->backupTableNames());
        self::assertContains('ip_conflict_monitor', $this->app()->backupTables()->backupTableNames());
    }

    public function testBackupRestorePreservesConflictHistoryAndMonitorState(): void
    {
        $repository = $this->app()->ipConflicts();
        $network = $this->app()->config()->dhcpNetwork;
        $startedAt = new DateTimeImmutable('-15 minutes UTC');
        $repository->reconcile($network, ['192.0.2.70' => [
            '02:00:00:00:00:70' => true,
            '02:00:00:00:00:71' => true,
        ]], $startedAt);
        $repository->reconcile($network, [], $startedAt->modify('+5 minutes'));
        $repository->reconcile($network, ['192.0.2.72' => [
            '02:00:00:00:00:72' => true,
            '02:00:00:00:00:73' => true,
        ]], $startedAt->modify('+10 minutes'));

        $path = tempnam(sys_get_temp_dir(), 'fenping-conflicts-');
        self::assertIsString($path);
        try {
            $written = $this->app()->backupArchives()->backupWriteDatabaseJson($path);
            self::assertGreaterThanOrEqual(7, $written['rows']);
            $document = $this->app()->backupTools()->backupReadJson($path, 'db.json');
            self::assertCount(2, $document['tables']['ip_conflicts']['rows']);
            self::assertCount(4, $document['tables']['ip_conflict_devices']['rows']);
            self::assertCount(1, $document['tables']['ip_conflict_monitor']['rows']);

            $database = $this->app()->database()->connection();
            $database->exec('DELETE FROM ip_conflict_devices');
            $database->exec('DELETE FROM ip_conflicts');
            $database->exec('DELETE FROM ip_conflict_monitor');
            self::assertSame([], $repository->active());

            $this->app()->backupDocuments()->backupRestoreDatabase($document);
            self::assertCount(1, $repository->active());
            self::assertSame('192.0.2.72', $repository->active()[0]['ip']);
            self::assertSame(2, (int) $database->query('SELECT COUNT(*) FROM ip_conflicts')->fetchColumn());
            self::assertSame(4, (int) $database->query('SELECT COUNT(*) FROM ip_conflict_devices')->fetchColumn());
            self::assertSame('ok', $repository->monitors()[0]['status']);
            self::assertSame([], $this->app()->database()->integrityErrors());
        } finally {
            unlink($path);
        }
    }
}

final readonly class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $date) {}
    public function now(): DateTimeImmutable { return $this->date; }
}

final readonly class StubProcessRunner implements ProcessRunner
{
    public function __construct(private ProcessResult $result) {}
    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        return $this->result;
    }
}

final class RouteAwareConflictProcessRunner implements ProcessRunner
{
    public array $commands = [];

    public function __construct(private readonly ProcessResult $scanResult) {}

    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        $this->commands[] = $command;
        if ($command === ['ip', '-4', 'route', 'show']) {
            return new ProcessResult(0, "172.17.0.0/16 dev docker0 proto kernel scope link src 172.17.0.1\n", '');
        }
        return $this->scanResult;
    }
}

final class RoutedConflictProcessRunner implements ProcessRunner
{
    public array $commands = [];

    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        $this->commands[] = $command;
        return new ProcessResult(0, "198.51.100.0/24 via 192.0.2.1 dev eth0 src 192.0.2.100\n", '');
    }
}
