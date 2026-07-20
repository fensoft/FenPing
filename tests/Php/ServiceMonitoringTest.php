<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;
use FenPing\Application;
use FenPing\Realtime\NullLiveUpdatePublisher;
use FenPing\Service\ServiceProbe;
use FenPing\Service\ServiceProbeResult;

final class ServiceMonitoringTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
        if ($this->app()->auth()->isAuthenticated()) $this->app()->auth()->logout();
    }

    protected function tearDown(): void
    {
        if ($this->app()->auth()->isAuthenticated()) $this->app()->auth()->logout();
    }

    public function testPinsRemainGuestReadableAndUnavailableAfterAServiceDisappears(): void
    {
        $ip = '192.0.2.44';
        $scan = $this->app()->scanJobs()->start($ip, 'deep');
        $this->app()->scanJobs()->complete($scan, $this->scanResult([$this->port(443, 'https')]));

        $guest = $this->app()->api()->handle($this->request('POST', '/api/services/pins', [
            'ip' => $ip, 'protocol' => 'tcp', 'port' => 443,
        ]));
        self::assertSame(403, $guest->status);

        self::assertTrue($this->app()->auth()->login(''));
        $created = $this->app()->api()->handle($this->request('POST', '/api/services/pins', [
            'ip' => $ip, 'protocol' => 'tcp', 'port' => 443,
        ]));
        self::assertSame(200, $created->status);
        $id = json_decode($created->body, true, flags: JSON_THROW_ON_ERROR)['service']['id'];
        $this->app()->auth()->logout();

        $database = $this->app()->database()->connection();
        $database->exec('PRAGMA query_only=ON');
        try {
            $listed = $this->services();
        } finally {
            $database->exec('PRAGMA query_only=OFF');
        }
        self::assertCount(1, $listed['services']);
        self::assertTrue($listed['services'][0]['important']);
        self::assertTrue($listed['important_services'][0]['available']);

        $empty = $this->app()->scanJobs()->start($ip, 'deep');
        $this->app()->scanJobs()->complete($empty, $this->scanResult([]));
        $missing = $this->services()['important_services'][0];
        self::assertSame($id, $missing['id']);
        self::assertFalse($missing['available']);
        self::assertSame('https', $missing['service']);

        $rediscovered = $this->app()->scanJobs()->start($ip, 'deep');
        $this->app()->scanJobs()->complete($rediscovered, $this->scanResult([[
            'protocol' => 'tcp', 'port' => 443, 'state' => 'open',
            'service' => 'ssl/http', 'product' => 'nginx', 'version' => '1.28',
            'details' => 'nginx 1.28', 'tunnel' => 'ssl',
        ]]));
        $reappeared = $this->services()['important_services'][0];
        self::assertSame($id, $reappeared['id']);
        self::assertTrue($reappeared['available']);
        self::assertSame('ssl/http', $reappeared['service']);
        self::assertSame('nginx 1.28', $reappeared['version']);
        $persisted = $database->query(
            "SELECT service, version, tunnel FROM monitored_services WHERE id=$id",
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['service' => 'ssl/http', 'version' => 'nginx 1.28', 'tunnel' => 'ssl'], $persisted);
    }

    public function testManualCrudChecksImmediatelyAndSuppressesTheInitialTransition(): void
    {
        $probe = new QueueServiceProbe(
            new ServiceProbeResult(true, 'HTTP 204'),
            new ServiceProbeResult(false, 'HTTP 503'),
            new ServiceProbeResult(false, 'HTTP 503'),
            new ServiceProbeResult(true, 'Proxy exit 8.8.8.8', '8.8.8.8'),
        );
        $app = Application::forConfig($this->app()->config(), new NullLiveUpdatePublisher(), null, $probe);
        $app->database()->initialize();
        self::assertTrue($app->auth()->login(''));

        $created = $app->api()->handle($this->request('POST', '/api/services/manual', [
            'name' => 'Status page', 'type' => 'https', 'url' => 'https://status.example.test/health',
        ]));
        self::assertSame(200, $created->status);
        $service = json_decode($created->body, true, flags: JSON_THROW_ON_ERROR)['service'];
        self::assertSame('healthy', $service['check_status']);
        self::assertSame('HTTP 204', $service['check_detail']);

        $checked = $app->api()->handle($this->request('POST', '/api/services/manual/' . $service['id'] . '/check'));
        self::assertSame('unhealthy', json_decode($checked->body, true, flags: JSON_THROW_ON_ERROR)['service']['check_status']);
        $again = $app->api()->handle($this->request('POST', '/api/services/manual/' . $service['id'] . '/check'));
        self::assertSame('unhealthy', json_decode($again->body, true, flags: JSON_THROW_ON_ERROR)['service']['check_status']);

        $duplicate = $app->api()->handle($this->request('POST', '/api/services/manual', [
            'name' => 'Duplicate', 'type' => 'https', 'url' => 'https://status.example.test/health',
        ]));
        self::assertSame(409, $duplicate->status);

        $deleted = $app->api()->handle($this->request('DELETE', '/api/services/manual/' . $service['id']));
        self::assertSame(['deleted' => true], json_decode($deleted->body, true));

        $socks = $app->api()->handle($this->request('POST', '/api/services/manual', [
            'name' => 'Remote SOCKS', 'type' => 'socks5', 'host' => 'socks.example.test', 'port' => 1080,
        ]));
        self::assertSame(200, $socks->status);
        $socksService = json_decode($socks->body, true, flags: JSON_THROW_ON_ERROR)['service'];
        self::assertSame('socks5', $socksService['type']);
        self::assertSame('healthy', $socksService['check_status']);
        self::assertSame('8.8.8.8', $socksService['observed_ip']);
        $app->auth()->logout();
    }

    public function testManualValidationRejectsCredentialsAndInvalidTargets(): void
    {
        self::assertTrue($this->app()->auth()->login(''));
        foreach ([
            ['name' => 'Bad URL', 'type' => 'https', 'url' => 'https://user:pass@example.test/'],
            ['name' => 'Bad host', 'type' => 'ssh', 'host' => 'host name', 'port' => 22],
            ['name' => 'Bad port', 'type' => 'proxy', 'host' => 'proxy.example.test', 'port' => 70000],
        ] as $input) {
            $response = $this->app()->api()->handle($this->request('POST', '/api/services/manual', $input));
            self::assertSame(400, $response->status);
        }
    }

    public function testBackupAndRestorePreserveMonitoredServicesWithoutChangingTheDocumentFormat(): void
    {
        self::assertContains('monitored_services', $this->app()->backupTables()->backupTableNames());
        $database = $this->app()->database()->connection();
        $database->exec(<<<'SQL'
            INSERT INTO monitored_services (
              source, type, name, target, check_status, check_detail, observed_ip, last_checked_at
            ) VALUES (
              'manual', 'https', 'Backup status', 'https://status.example.test/health',
              'healthy', 'HTTP 204', '203.0.113.10', CURRENT_TIMESTAMP
            )
            SQL);
        $path = tempnam(sys_get_temp_dir(), 'fenping-services-backup-');
        self::assertIsString($path);
        try {
            $this->app()->backupArchives()->backupWriteDatabaseJson($path);
            $document = $this->app()->backupTools()->backupReadJson($path, 'db.json');
            self::assertSame('1.6', $document['version']);
            self::assertCount(1, $document['tables']['monitored_services']['rows']);

            $database->exec('DELETE FROM monitored_services');
            $this->app()->backupDocuments()->backupRestoreDatabase($document);
            $restored = $database->query(
                "SELECT name, target, check_status, check_detail, observed_ip FROM monitored_services",
            )->fetch(\PDO::FETCH_ASSOC);
            self::assertSame([
                'name' => 'Backup status',
                'target' => 'https://status.example.test/health',
                'check_status' => 'healthy',
                'check_detail' => 'HTTP 204',
                'observed_ip' => '203.0.113.10',
            ], $restored);
        } finally {
            @unlink($path);
        }
    }

    private function services(): array
    {
        $response = $this->app()->api()->handle($this->request('GET', '/api/services'));
        self::assertSame(200, $response->status);
        return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
    }

    private function request(string $method, string $uri, ?array $body = null): Request
    {
        return new Request($method, $uri, [], [], [], ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri], [], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function scanResult(array $ports): array
    {
        return ['status' => 'up', 'duration' => 1, 'ports' => $ports];
    }

    private function port(int $port, string $service): array
    {
        return ['protocol' => 'tcp', 'port' => $port, 'state' => 'open', 'service' => $service];
    }
}

final class QueueServiceProbe implements ServiceProbe
{
    /** @var list<ServiceProbeResult> */
    private array $results;

    public function __construct(ServiceProbeResult ...$results)
    {
        $this->results = $results;
    }

    public function check(array $service): ServiceProbeResult
    {
        return array_shift($this->results) ?? new ServiceProbeResult(false, 'unexpected check');
    }
}
