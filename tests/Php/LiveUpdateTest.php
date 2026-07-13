<?php

declare(strict_types=1);

namespace FenPing\Tests;

use DateTimeImmutable;
use FenPing\Application;
use FenPing\Api\ApiKernel;
use FenPing\Api\AuthPolicy;
use FenPing\Api\Controller\Controller;
use FenPing\Api\Controller\RouteAdapter;
use FenPing\Api\HttpException;
use FenPing\Api\JsonResponse;
use FenPing\Api\Request;
use FenPing\Api\ResponseException;
use FenPing\Api\Route;
use FenPing\Auth\AuthService;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Realtime\NchanLiveUpdatePublisher;

final class LiveUpdateTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testPayloadDeduplicatesScopesAndUsesUtc(): void
    {
        $payload = NchanLiveUpdatePublisher::payload(
            [LiveUpdateScope::Status, LiveUpdateScope::Hosts, LiveUpdateScope::Status],
            new DateTimeImmutable('2026-07-13T14:00:00+02:00'),
        );

        self::assertSame([
            'version' => 1,
            'scopes' => ['hosts', 'status'],
            'occurred_at' => '2026-07-13T12:00:00Z',
        ], json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(
            ['version' => 1, 'scopes' => ['all'], 'occurred_at' => '2026-07-13T12:00:00Z'],
            json_decode((string) NchanLiveUpdatePublisher::payload(
                [LiveUpdateScope::Hosts, LiveUpdateScope::All],
                new DateTimeImmutable('2026-07-13T12:00:00Z'),
            ), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertNull(NchanLiveUpdatePublisher::payload([]));
    }

    public function testTransportFailureDoesNotThrow(): void
    {
        (new NchanLiveUpdatePublisher(port: 1, timeoutSeconds: 0.01))->publish(LiveUpdateScope::Hosts);
        self::addToAssertionCount(1);
    }

    public function testApiPublishesOnlySuccessfulResponsesIncludingLegacyResponses(): void
    {
        $publisher = new RecordingLiveUpdatePublisher();
        $adapted = (new RouteAdapter())->adapt([[
            'method' => 'POST',
            'pattern' => 'legacy',
            'handler' => static function (array $params): never {
                throw new ResponseException(new JsonResponse(['queued' => true], 202));
            },
            'live' => [LiveUpdateScope::Scans],
        ]]);
        $controller = new class($adapted) implements Controller {
            public function __construct(private array $adapted) {}

            public function routes(): array
            {
                return [
                    new Route('POST', 'direct', static fn(array $params): array => ['ok' => true], AuthPolicy::Guest, [LiveUpdateScope::Hosts]),
                    ...$this->adapted,
                    new Route('POST', 'server-error', static fn(array $params): JsonResponse => new JsonResponse(['error' => 'no'], 500), AuthPolicy::Guest, [LiveUpdateScope::Status]),
                    new Route('POST', 'exception', static function (array $params): never {
                        throw new HttpException(409, 'conflict');
                    }, AuthPolicy::Guest, [LiveUpdateScope::Conflicts]),
                ];
            }
        };
        $api = new ApiKernel(new AuthService($this->app()->config()), [$controller], $publisher);

        self::assertSame(200, $api->handle($this->request('/api/direct'))->status);
        self::assertSame(202, $api->handle($this->request('/api/legacy'))->status);
        self::assertSame(500, $api->handle($this->request('/api/server-error'))->status);
        self::assertSame(409, $api->handle($this->request('/api/exception'))->status);
        self::assertSame([
            [LiveUpdateScope::Hosts],
            [LiveUpdateScope::Scans],
        ], $publisher->events);
    }

    public function testScanLifecycleAndOperationRecordingPublishUpdates(): void
    {
        $publisher = new RecordingLiveUpdatePublisher();
        $app = Application::forConfig($this->app()->config(), $publisher);

        $completed = $app->scanJobs()->start('192.0.2.20', 'lightweight');
        $app->scanJobs()->complete($completed, ['status' => 'down', 'duration' => 1, 'ports' => []]);
        $failed = $app->scanJobs()->start('192.0.2.21', 'standard');
        $app->scanJobs()->fail($failed, 'test failure');
        $timedOut = $app->scanJobs()->start('192.0.2.22', 'deep');
        $app->scanJobs()->timeout($timedOut, 'test timeout');
        $expired = $app->scanJobs()->start('192.0.2.23', 'standard');
        $app->database()->connection()->exec(
            "UPDATE scans SET date_begin=datetime('now', '-2 hours') WHERE id=$expired",
        );
        $app->scanJobs()->expireRunning(60);
        $app->scanJobs()->enqueue('192.0.2.24', 'lightweight');
        $app->scanJobs()->claimQueued(4);

        self::assertCount(9, $publisher->events);
        foreach ($publisher->events as $event) {
            self::assertSame([LiveUpdateScope::Scans], $event);
        }

        $publisher->events = [];
        $app->backend()->operations->started('live_update_test');
        $app->backend()->operations->succeeded('live_update_test');
        $app->backend()->operations->failed('live_update_test', 'expected test failure');
        self::assertSame([
            [LiveUpdateScope::Operations],
            [LiveUpdateScope::Operations],
            [LiveUpdateScope::Operations],
        ], $publisher->events);
    }

    public function testProgressEventsAreThrottledAndCancellationWins(): void
    {
        $publisher = new RecordingLiveUpdatePublisher();
        $app = Application::forConfig($this->app()->config(), $publisher);
        $id = $app->scanJobs()->start('192.0.2.30', 'standard');
        $publisher->events = [];

        $app->scanJobs()->updateProgress($id, 'port_scan', 30);
        $app->scanJobs()->updateProgress($id, 'port_scan', 30);
        $app->scanJobs()->updateProgress($id, 'port_scan', 20);
        $app->scanJobs()->updateProgress($id, 'service_detection', 30);
        self::assertCount(2, $publisher->events);

        $app->scanJobs()->cancel('192.0.2.30', $id);
        $app->scanJobs()->fail($id, 'late failure');
        $app->scanJobs()->timeout($id, 'late timeout');
        $app->scanJobs()->markCancelled($id);
        self::assertCount(4, $publisher->events);
        foreach ($publisher->events as $event) {
            self::assertSame([LiveUpdateScope::Scans], $event);
        }
        $metadata = $app->scanJobs()->findJob($id);
        self::assertSame('cancelled', $metadata['state']);
        self::assertSame(30, $metadata['progress_percent']);
        self::assertSame('cancelled', $metadata['progress_phase']);
    }

    private function request(string $uri): Request
    {
        return new Request('POST', $uri, [], [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $uri], []);
    }
}
