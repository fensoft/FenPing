<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\AuthPolicy;
use FenPing\Api\Controller\DockerNetworksController;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\RequestContext;
use FenPing\Config\AppConfig;
use FenPing\Docker\DockerNetworkCache;
use FenPing\Docker\DockerEngineClient;
use FenPing\Docker\DockerNetworkEventDebouncer;
use FenPing\Docker\DockerNetworkParser;
use FenPing\Docker\DockerNetworkRefreshGateway;
use FenPing\Docker\DockerNetworkRefreshService;
use FenPing\Docker\DockerNetworkSource;
use FenPing\Docker\DockerNetworkWatcher;
use FenPing\Docker\PrivilegedDockerNetworkRefreshGateway;
use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;
use FenPing\Realtime\LiveUpdateScope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DockerNetworkTest extends TestCase
{
    public function testParserReturnsOnlyOccupiedSupported24Slices(): void
    {
        $json = json_encode([
            [
                'Name' => 'bridge',
                'IPAM' => ['Config' => [['Subnet' => '172.17.0.0/16', 'Gateway' => '172.17.0.1']]],
                'Containers' => [
                    'a' => ['Name' => 'web', 'IPv4Address' => '172.17.0.2/16'],
                    'b' => ['Name' => 'worker', 'IPv4Address' => '172.17.4.9/16'],
                ],
            ],
            [
                'Name' => 'shared',
                'IPAM' => ['Config' => [['Subnet' => '172.17.0.0/24']]],
                'Containers' => [],
            ],
            [
                'Name' => 'empty-24',
                'IPAM' => ['Config' => [['Subnet' => '10.20.30.0/24']]],
                'Containers' => [],
            ],
            [
                'Name' => 'narrow',
                'IPAM' => ['Config' => [['Subnet' => '192.0.2.128/25', 'Gateway' => '192.0.2.129']]],
                'Containers' => ['c' => ['IPv4Address' => '192.0.2.130/25']],
            ],
            [
                'Name' => 'ipv6',
                'IPAM' => ['Config' => [['Subnet' => 'fd00::/64', 'Gateway' => 'fd00::1']]],
                'Containers' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        self::assertSame([
            ['cidr' => '10.20.30.0/24', 'names' => ['empty-24']],
            [
                'cidr' => '172.17.0.0/24',
                'names' => ['bridge', 'shared'],
                'gateways' => [
                    ['network' => 'bridge', 'ip' => '172.17.0.1'],
                ],
                'containers' => [
                    ['network' => 'bridge', 'container' => 'web', 'ip' => '172.17.0.2'],
                ],
            ],
            [
                'cidr' => '172.17.4.0/24', 'names' => ['bridge'],
                'containers' => [
                    ['network' => 'bridge', 'container' => 'worker', 'ip' => '172.17.4.9'],
                ],
            ],
        ], (new DockerNetworkParser())->parse($json));
    }

    public function testParserRejectsMalformedDockerResponse(): void
    {
        $this->expectException(RuntimeException::class);
        (new DockerNetworkParser())->parse('{bad json');
    }

    public function testCacheExposesContainerIdentitiesAndAcceptsOlderRows(): void
    {
        $directory = $this->temporaryDirectory();
        $cache = new DockerNetworkCache($directory . '/networks.json');
        $cache->replace([
            '192.0.2.0/24',
            [
                'cidr' => '198.51.100.0/24',
                'names' => ['app_default'],
                'gateways' => [
                    ['network' => 'app_default', 'ip' => '198.51.100.1'],
                    ['network' => '', 'ip' => '198.51.100.2'],
                ],
                'containers' => [
                    ['network' => 'app_default', 'container' => 'camera', 'ip' => '198.51.100.20'],
                    ['network' => '', 'container' => 'invalid', 'ip' => '198.51.100.21'],
                ],
            ],
        ], 123);

        self::assertSame([
            [
                'cidr' => '198.51.100.0/24',
                'network' => 'app_default',
                'container' => 'camera',
                'ip' => '198.51.100.20',
            ],
        ], $cache->containers());
        self::assertSame([
            [
                'cidr' => '198.51.100.0/24',
                'network' => 'app_default',
                'ip' => '198.51.100.1',
            ],
        ], $cache->gateways());
        self::assertSame('camera', $cache->container('app_default', 'camera')['container']);
        self::assertSame([], $cache->containersForIp('192.0.2.0/24', '192.0.2.20'));
        self::assertNull($cache->container('app_default', 'renamed'));
    }

    public function testEngineClientRequiresAUnixSocket(): void
    {
        $client = new DockerEngineClient(
            new DockerNetworkTestProcessRunner(new ProcessResult(0, '[]')),
            new DockerNetworkParser(),
            '/dev/null',
        );
        self::assertFalse($client->available());
    }

    public function testEngineClientInspectsEachNetworkThroughTheUnixSocket(): void
    {
        $directory = $this->temporaryDirectory();
        $socketPath = $directory . '/docker.sock';
        $socket = stream_socket_server('unix://' . $socketPath);
        self::assertIsResource($socket);
        $processes = new DockerNetworkTestProcessRunner([
            new ProcessResult(0, json_encode([[
                'Id' => 'network-id',
                'Name' => 'bridge',
                'IPAM' => ['Config' => [['Subnet' => '172.17.0.0/16', 'Gateway' => '172.17.0.1']]],
            ]], JSON_THROW_ON_ERROR)),
            new ProcessResult(0, json_encode([
                'Id' => 'network-id',
                'Name' => 'bridge',
                'IPAM' => ['Config' => [['Subnet' => '172.17.0.0/16', 'Gateway' => '172.17.0.1']]],
                'Containers' => [
                    'container-id' => [
                        'Name' => 'buildx_buildkit_fenping-multiarch0',
                        'IPv4Address' => '172.17.0.2/16',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        try {
            $networks = (new DockerEngineClient(
                $processes,
                new DockerNetworkParser(),
                $socketPath,
            ))->networks();
        } finally {
            fclose($socket);
        }

        self::assertSame('bridge', $networks[0]['gateways'][0]['network']);
        self::assertSame(
            'buildx_buildkit_fenping-multiarch0',
            $networks[0]['containers'][0]['container'],
        );
        self::assertSame('http://localhost/networks', $processes->commands[0][10]);
        self::assertSame('http://localhost/networks/network-id', $processes->commands[1][10]);
    }

    public function testRefreshCacheCoalescesApiCallsAndPreservesSuccessAfterFailure(): void
    {
        $directory = $this->temporaryDirectory();
        $cache = new DockerNetworkCache($directory . '/networks.json');
        $source = new DockerNetworkTestSource([['cidr' => '172.17.0.0/24', 'names' => ['bridge']]]);
        $refresh = new DockerNetworkRefreshService($source, $cache, $directory . '/refresh.lock', 60);

        self::assertSame('refreshed', $refresh->refresh(true)['status']);
        self::assertSame(['172.17.0.0/24'], $cache->networks());
        self::assertSame(['172.17.0.0/24' => ['bridge']], $cache->networkNames());
        $source->networks = [['cidr' => '172.18.0.0/24', 'names' => ['app_default']]];
        self::assertSame('unchanged', $refresh->refresh(false, false)['status']);
        self::assertSame(1, $source->calls);

        self::assertSame('refreshed', $refresh->refresh(true)['status']);
        self::assertSame(['172.18.0.0/24'], $cache->networks());
        $source->error = new RuntimeException('daemon unavailable');
        $cache->replace([['cidr' => '172.18.0.0/24', 'names' => ['app_default']]], time() - 120);
        self::assertSame('stale', $refresh->refresh(false, false)['status']);
        self::assertSame(['172.18.0.0/24'], $cache->networks());
        try {
            $refresh->refresh(true);
            self::fail('refresh failure was not reported');
        } catch (RuntimeException) {
        }
        self::assertSame(['172.18.0.0/24'], $cache->networks());
    }

    public function testRefreshPublishesOnlyWhenEffectiveNetworksChange(): void
    {
        $directory = $this->temporaryDirectory();
        $cache = new DockerNetworkCache($directory . '/networks.json');
        $source = new DockerNetworkTestSource([['cidr' => '172.17.0.0/24', 'names' => ['bridge']]]);
        $publisher = new RecordingLiveUpdatePublisher();
        $refresh = new DockerNetworkRefreshService(
            $source,
            $cache,
            $directory . '/refresh.lock',
            60,
            $publisher,
        );

        $refresh->refresh(true);
        $refresh->refresh(true);
        self::assertSame([[LiveUpdateScope::Networks]], $publisher->events);

        $source->networks = [['cidr' => '172.17.0.0/24', 'names' => ['bridge', 'shared']]];
        $refresh->refresh(true);
        self::assertSame([[LiveUpdateScope::Networks], [LiveUpdateScope::Networks]], $publisher->events);

        $source->networks = [[
            'cidr' => '172.17.0.0/24',
            'names' => ['bridge', 'shared'],
            'gateways' => [['network' => 'bridge', 'ip' => '172.17.0.1']],
        ]];
        $refresh->refresh(true);
        self::assertSame([[LiveUpdateScope::Networks], [LiveUpdateScope::Networks], [LiveUpdateScope::Networks]], $publisher->events);
    }

    public function testAppConfigMergesValidCacheWithoutWeakeningManualValidation(): void
    {
        $directory = $this->temporaryDirectory();
        $cachePath = $directory . '/networks.json';
        (new DockerNetworkCache($cachePath))->replace([
            ['cidr' => '192.0.2.0/24', 'names' => ['dhcp-docker']],
            ['cidr' => '198.51.100.0/24', 'names' => ['manual-docker']],
            ['cidr' => '203.0.113.0/24', 'names' => ['app_default']],
            'invalid',
        ]);
        $previous = getenv('DOCKER_NETWORK_CACHE');
        try {
            putenv('DOCKER_NETWORK_CACHE=' . $cachePath);
            $config = AppConfig::fromEnvironment(dirname(__DIR__, 2));
            self::assertSame(
                ['198.51.100.0/24', '203.0.113.0/24'],
                array_map(static fn($network): string => $network->cidr, $config->extraNetworks),
            );
            self::assertSame([
                '192.0.2.0/24' => ['dhcp-docker'],
                '198.51.100.0/24' => ['manual-docker'],
                '203.0.113.0/24' => ['app_default'],
            ], $config->dockerNetworkNames);
        } finally {
            $previous === false ? putenv('DOCKER_NETWORK_CACHE') : putenv('DOCKER_NETWORK_CACHE=' . $previous);
        }
    }

    public function testEventFilteringDebounceAndBackoff(): void
    {
        self::assertTrue(DockerNetworkWatcher::isRelevantEvent('{"Type":"network","Action":"connect"}'));
        self::assertFalse(DockerNetworkWatcher::isRelevantEvent('{"Type":"container","Action":"start"}'));
        self::assertFalse(DockerNetworkWatcher::isRelevantEvent('not json'));
        self::assertSame(2, DockerNetworkWatcher::nextBackoff(1));
        self::assertSame(30, DockerNetworkWatcher::nextBackoff(30));

        $debouncer = new DockerNetworkEventDebouncer(1.0);
        $debouncer->mark(10.0);
        self::assertFalse($debouncer->due(10.9));
        $debouncer->mark(10.5);
        self::assertTrue($debouncer->due(11.5));
        self::assertTrue($debouncer->consume());
        self::assertFalse($debouncer->consume());
    }

    public function testGuestControllerRejectsParametersAndUsesFixedGateway(): void
    {
        $gateway = new DockerNetworkTestGateway();
        $route = (new DockerNetworksController($gateway))->routes()[0];
        self::assertSame(AuthPolicy::Guest, $route->auth);

        RequestContext::set(new Request('POST', '/api/networks/refresh', [], [], [], [], []));
        try {
            self::assertSame('refreshed', ($route->handler)(new Request('POST', '/api/networks/refresh', [], [], [], [], []), [])['status']);
            self::assertSame(1, $gateway->calls);
        } finally {
            RequestContext::clear();
        }

        RequestContext::set(new Request('POST', '/api/networks/refresh', [], [], [], [], [], '{"force":true}'));
        try {
            ($route->handler)(new Request('POST', '/api/networks/refresh', [], [], [], [], []), []);
            self::fail('refresh parameters were accepted');
        } catch (HttpException $error) {
            self::assertSame(400, $error->status);
        } finally {
            RequestContext::clear();
        }
    }

    public function testPrivilegedGatewayUsesOnlyExactDoasCommand(): void
    {
        $processes = new DockerNetworkTestProcessRunner(new ProcessResult(
            0,
            '{"status":"refreshed","networks":2,"updated_at":123}',
        ));
        $result = (new PrivilegedDockerNetworkRefreshGateway($processes, '/opt/fenping'))->refresh();
        self::assertSame('refreshed', $result['status']);
        self::assertSame([
            '/usr/bin/doas', '/usr/bin/php', '/opt/fenping/cli.php',
            'docker-networks-refresh', '--api',
        ], $processes->command);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/fenping-docker-test-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0700));
        return $directory;
    }
}

final class DockerNetworkTestSource implements DockerNetworkSource
{
    public int $calls = 0;
    public ?RuntimeException $error = null;

    /** @param list<array{cidr: string, names: list<string>}> $networks */
    public function __construct(public array $networks)
    {
    }

    public function available(): bool { return true; }
    public function networks(): array
    {
        $this->calls++;
        if ($this->error !== null) throw $this->error;
        return $this->networks;
    }
    public function eventCommand(): array { return ['false']; }
}

final class DockerNetworkTestGateway implements DockerNetworkRefreshGateway
{
    public int $calls = 0;
    public function refresh(): array
    {
        $this->calls++;
        return ['status' => 'refreshed', 'networks' => 1, 'updated_at' => 123];
    }
}

final class DockerNetworkTestProcessRunner implements ProcessRunner
{
    public array $command = [];
    public array $commands = [];
    /** @var list<ProcessResult> */
    private array $results;
    public function __construct(ProcessResult|array $results)
    {
        $this->results = $results instanceof ProcessResult ? [$results] : array_values($results);
    }
    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        $this->command = $command;
        $this->commands[] = $command;
        return array_shift($this->results) ?? new ProcessResult(1, '', 'unexpected process call');
    }
}
