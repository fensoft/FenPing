<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;

final class AuditLogTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
        if ($this->app()->auth()->isAuthenticated()) {
            $this->app()->auth()->logout();
        }
    }

    protected function tearDown(): void
    {
        if ($this->app()->auth()->isAuthenticated()) {
            $this->app()->auth()->logout();
        }
    }

    public function testLoginAttemptsAreRecordedWithoutCredentialsAndAuditRequiresAdmin(): void
    {
        $guest = $this->app()->api()->handle($this->request('GET', '/api/audit'));
        self::assertSame(403, $guest->status);

        $failed = $this->app()->api()->handle($this->request(
            'POST', '/api/auth/login', ['password' => 'wrong'], remote: '198.51.100.40', userAgent: 'Audit test browser',
        ));
        self::assertSame(403, $failed->status);

        $login = $this->app()->api()->handle($this->request(
            'POST', '/api/auth/login', ['password' => ''], remote: '198.51.100.41', userAgent: 'Audit test browser',
        ));
        self::assertSame(200, $login->status);

        $response = $this->app()->api()->handle($this->request('GET', '/api/audit'));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['auth.login_succeeded', 'auth.login_failed'], array_column($body['events'], 'action'));
        self::assertSame(['admin', 'anonymous'], array_column($body['events'], 'actor'));
        self::assertSame(['198.51.100.41', '198.51.100.40'], array_column($body['events'], 'remote_address'));
        self::assertSame([], $body['events'][0]['details']);
        self::assertStringNotContainsString('wrong', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testFilteringSearchingAndPaginationReturnStructuredDetails(): void
    {
        $this->app()->audit()->record('host.updated', 'host', 42, 'Updated host Core router', [
            'changes' => ['dns' => ['before' => '', 'after' => '192.0.2.53']],
        ]);
        $this->app()->audit()->record('scan.queued', 'scan', 90, 'Queued manual deep scan for 192.0.2.42');
        $this->app()->audit()->record('host.deleted', 'host', 41, 'Deleted DHCP reservation for Printer');

        self::assertTrue($this->app()->auth()->login(''));
        $response = $this->app()->api()->handle($this->request(
            'GET', '/api/audit?resource_type=host&search=Core&per_page=1',
            query: ['resource_type' => 'host', 'search' => 'Core', 'per_page' => '1'],
        ));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['pagination']['total']);
        self::assertSame('host.updated', $body['events'][0]['action']);
        self::assertSame('192.0.2.53', $body['events'][0]['details']['changes']['dns']['after']);
        self::assertContains('scan.queued', $body['filters']['actions']);
        self::assertSame(['host', 'scan'], $body['filters']['resource_types']);
    }

    public function testManualScanCancellationIsAuditedOnlyAfterSuccess(): void
    {
        $queued = $this->app()->scanJobs()->enqueue('192.0.2.80', 'standard');
        $id = (int) $queued['metadata']['id'];
        self::assertTrue($this->app()->auth()->login(''));

        $response = $this->app()->api()->handle($this->request('POST', "/api/scans/192.0.2.80/{$id}/cancel"));
        self::assertSame(200, $response->status);
        $events = $this->app()->audit()->page(['action' => 'scan.cancelled'])['events'];
        self::assertCount(1, $events);
        self::assertSame((string) $id, $events[0]['resource_id']);
        self::assertSame('192.0.2.80', $events[0]['details']['ip']);

        $missing = $this->app()->api()->handle($this->request('POST', '/api/scans/192.0.2.80/999999/cancel'));
        self::assertSame(404, $missing->status);
        self::assertCount(1, $this->app()->audit()->page(['action' => 'scan.cancelled'])['events']);
    }

    private function request(
        string $method,
        string $uri,
        ?array $body = null,
        array $query = [],
        string $remote = '192.0.2.200',
        string $userAgent = 'PHPUnit',
    ): Request {
        return new Request(
            $method,
            $uri,
            $query,
            [],
            [],
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $uri,
                'REMOTE_ADDR' => $remote,
                'HTTP_USER_AGENT' => $userAgent,
            ],
            [],
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
