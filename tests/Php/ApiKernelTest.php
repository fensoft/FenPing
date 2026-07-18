<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;

final class ApiKernelTest extends IntegrationTestCase
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

    public function testProfilesEndpointKeepsDirectJsonShape(): void
    {
        $response = $this->app()->api()->handle($this->request('GET', '/api/scans/profiles'));
        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['lightweight', 'standard', 'deep'], array_column($body['profiles'], 'id'));
    }

    public function testTypedRouterPreservesValidationAndMethodErrors(): void
    {
        $invalid = $this->app()->api()->handle($this->request('GET', '/api/hosts/by-ip/not-an-ip/detail'));
        self::assertSame(400, $invalid->status);
        self::assertSame(['error' => 'invalid ip'], json_decode($invalid->body, true));

        $method = $this->app()->api()->handle($this->request('PATCH', '/api/scans/profiles'));
        self::assertSame(405, $method->status);
    }

    public function testInventoryReturnsNetworkMetadataAndRejectsUnknownNetworks(): void
    {
        $response = $this->app()->api()->handle($this->request('GET', '/api/inventory'));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('192.0.2.0/24', $body['selected_network']);
        self::assertSame('192.0.2.0/24', $body['dhcp_network']);
        self::assertTrue($body['networks'][0]['selectable']);
        self::assertSame([], $body['available_tags']);
        self::assertSame([], $body['saved_filters']);

        $request = new Request('GET', '/api/inventory?network=203.0.113.0%2F24', ['network' => '203.0.113.0/24'], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/inventory'], []);
        $unknown = $this->app()->api()->handle($request);
        self::assertSame(400, $unknown->status);
    }

    public function testInventoryReportsEffectivePortsFromLatestUsableScan(): void
    {
        $ip = '192.0.2.10';
        $this->app()->pingRepository()->save([
            ['ip' => $ip, 'mac' => '02:00:00:00:00:10', 'status' => 'Up'],
        ]);

        $deep = $this->app()->scanJobs()->start($ip, 'deep');
        $this->app()->scanJobs()->complete($deep, $this->scanResult([
            $this->scanPort(22, 'ssh'),
            $this->scanPort(443, 'https'),
        ]));

        $fast = $this->app()->scanJobs()->start($ip, 'lightweight');
        $this->app()->scanJobs()->complete($fast, $this->scanResult([]));
        $fastInventory = $this->inventoryHost($ip);
        self::assertSame($fast, $fastInventory['scan']['id']);
        self::assertSame(0, $fastInventory['scan']['ports_count']);
        self::assertSame(2, $fastInventory['scan']['effective_ports_count']);

        $standard = $this->app()->scanJobs()->start($ip, 'standard');
        $this->app()->scanJobs()->complete($standard, $this->scanResult([
            $this->scanPort(22, 'ssh'),
            $this->scanPort(80, 'http'),
        ]));
        $standardInventory = $this->inventoryHost($ip);
        self::assertSame(2, $standardInventory['scan']['ports_count']);
        self::assertSame(3, $standardInventory['scan']['effective_ports_count']);

        $effectiveResponse = $this->app()->api()->handle(
            $this->request('GET', "/api/scans/$ip/history/$standard"),
        );
        self::assertSame(200, $effectiveResponse->status);
        $effective = json_decode($effectiveResponse->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($standardInventory['scan']['effective_ports_count'], $effective['ports_count']);

        $down = $this->app()->scanJobs()->start($ip, 'lightweight');
        $this->app()->scanJobs()->complete($down, $this->scanResult([], 'down'));
        $downInventory = $this->inventoryHost($ip);
        self::assertSame($down, $downInventory['scan']['id']);
        self::assertSame('down', $downInventory['scan']['status']);
        self::assertSame(0, $downInventory['scan']['ports_count']);
        self::assertSame(3, $downInventory['scan']['effective_ports_count']);
        self::assertTrue($downInventory['scan']['result_available']);
    }

    public function testIpamReturnsEveryConfiguredSubnetAndItsObservedDevices(): void
    {
        $this->app()->pingRepository()->save([
            ['ip' => '192.0.2.10', 'mac' => '02:00:00:00:00:10', 'status' => 'Up'],
            ['ip' => '198.51.100.10', 'mac' => '02:00:00:00:01:10', 'status' => 'Up'],
            ['ip' => '203.0.113.10', 'mac' => '02:00:00:00:02:10', 'status' => 'Up'],
        ]);
        $this->app()->ipConflicts()->reconcile(
            $this->app()->config()->extraNetworks[0],
            ['198.51.100.20' => [
                '02:00:00:00:01:20' => true,
                '02:00:00:00:01:21' => true,
            ]],
            new \DateTimeImmutable(),
        );

        $response = $this->app()->api()->handle($this->request('GET', '/api/ipam'));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('192.0.2.0/24', $body['dhcp_network']);
        self::assertSame(
            ['192.0.2.0/24', '198.51.100.0/24'],
            array_column($body['networks'], 'cidr'),
        );
        $pending = array_column($body['pending'], null, 'mac');
        self::assertSame('192.0.2.0/24', $pending['02:00:00:00:00:10']['network']);
        self::assertTrue($pending['02:00:00:00:00:10']['dhcp']);
        self::assertSame('198.51.100.0/24', $pending['02:00:00:00:01:10']['network']);
        self::assertFalse($pending['02:00:00:00:01:10']['dhcp']);
        self::assertArrayNotHasKey('02:00:00:00:02:10', $pending);
        self::assertSame('198.51.100.0/24', $body['conflicts'][0]['network']);
        self::assertCount(2, $body['conflict_monitor']['monitors']);
    }

    public function testSavedInventoryFilterCrudRequiresAuthenticationAndRemainsGuestReadable(): void
    {
        $guest = $this->app()->api()->handle($this->request(
            'POST',
            '/api/inventory/saved-filters',
            ['name' => 'Infrastructure', 'tags' => ['Server']],
        ));
        self::assertSame(403, $guest->status);

        self::assertTrue($this->app()->auth()->login(''));
        $invalid = $this->app()->api()->handle($this->request(
            'POST',
            '/api/inventory/saved-filters',
            ['name' => 'Empty', 'tags' => []],
        ));
        self::assertSame(400, $invalid->status);

        $created = $this->app()->api()->handle($this->request(
            'POST',
            '/api/inventory/saved-filters',
            ['name' => 'Infrastructure', 'tags' => [' Server ', 'server', 'Core']],
        ));
        self::assertSame(200, $created->status);
        $filter = json_decode($created->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Infrastructure', $filter['name']);
        self::assertSame(['Core', 'Server'], $filter['tags']);

        $duplicate = $this->app()->api()->handle($this->request(
            'POST',
            '/api/inventory/saved-filters',
            ['name' => 'infrastructure', 'tags' => ['Printer']],
        ));
        self::assertSame(409, $duplicate->status);

        $updated = $this->app()->api()->handle($this->request(
            'PUT',
            '/api/inventory/saved-filters/' . $filter['id'],
            ['name' => 'Critical systems', 'tags' => ['Printer', 'SERVER']],
        ));
        self::assertSame(200, $updated->status);
        $updatedFilter = json_decode($updated->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Critical systems', $updatedFilter['name']);
        self::assertSame(['Printer', 'Server'], $updatedFilter['tags']);

        $missing = $this->app()->api()->handle($this->request(
            'PUT',
            '/api/inventory/saved-filters/999999',
            ['name' => 'Missing', 'tags' => ['Server']],
        ));
        self::assertSame(404, $missing->status);

        $this->app()->auth()->logout();
        $inventory = $this->app()->api()->handle($this->request('GET', '/api/inventory'));
        self::assertSame(200, $inventory->status);
        $inventoryBody = json_decode($inventory->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Printer', 'Server'], $inventoryBody['available_tags']);
        self::assertSame([$updatedFilter], $inventoryBody['saved_filters']);

        $guestDelete = $this->app()->api()->handle($this->request(
            'DELETE',
            '/api/inventory/saved-filters/' . $filter['id'],
        ));
        self::assertSame(403, $guestDelete->status);

        self::assertTrue($this->app()->auth()->login(''));
        $deleted = $this->app()->api()->handle($this->request(
            'DELETE',
            '/api/inventory/saved-filters/' . $filter['id'],
        ));
        self::assertSame(200, $deleted->status);
        self::assertSame(['deleted' => true], json_decode($deleted->body, true));

        $missingDelete = $this->app()->api()->handle(
            $this->request('DELETE', '/api/inventory/saved-filters/999999'),
        );
        self::assertSame(404, $missingDelete->status);
    }

    public function testScanCancellationRequiresLoginAndReturnsNormalizedPolicyPayloads(): void
    {
        $queued = $this->app()->scanJobs()->enqueue('192.0.2.80', 'standard');
        $id = (int) $queued['metadata']['id'];
        $uri = "/api/scans/192.0.2.80/$id/cancel";

        $guest = $this->app()->api()->handle($this->request('POST', $uri));
        self::assertSame(403, $guest->status);

        self::assertTrue($this->app()->auth()->login(''));
        $response = $this->app()->api()->handle($this->request('POST', $uri));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($body['cancellation_requested']);
        self::assertTrue($body['cancelled']);
        foreach ([
            'network', 'request_source', 'progress_percent', 'progress_phase',
            'progress_updated_at', 'queue_position', 'queue_reason',
            'budget_eligible_at', 'cancel_requested',
        ] as $field) {
            self::assertArrayHasKey($field, $body['metadata']);
        }

        $queue = $this->app()->api()->handle($this->request('GET', '/api/scans'));
        self::assertSame(200, $queue->status);
        $queueBody = json_decode($queue->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(4, $queueBody['policy']['global']['concurrency_limit']);
        self::assertSame(
            ['192.0.2.0/24', '198.51.100.0/24'],
            array_column($queueBody['policy']['networks'], 'network'),
        );

        $running = $this->app()->scanJobs()->start('192.0.2.82', 'standard');
        $accepted = $this->app()->api()->handle(
            $this->request('POST', "/api/scans/192.0.2.82/$running/cancel"),
        );
        self::assertSame(200, $accepted->status);
        $acceptedBody = json_decode($accepted->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($acceptedBody['cancelled']);
        self::assertSame('cancelled', $acceptedBody['metadata']['state']);
        self::assertTrue($acceptedBody['metadata']['cancel_requested']);

        $missing = $this->app()->api()->handle(
            $this->request('POST', '/api/scans/192.0.2.80/999999/cancel'),
        );
        self::assertSame(404, $missing->status);
        $complete = $this->app()->scanJobs()->start('192.0.2.81', 'lightweight');
        $this->app()->scanJobs()->complete($complete, ['status' => 'down', 'duration' => 1, 'ports' => []]);
        $conflict = $this->app()->api()->handle(
            $this->request('POST', "/api/scans/192.0.2.81/$complete/cancel"),
        );
        self::assertSame(409, $conflict->status);
    }

    private function request(string $method, string $uri, ?array $body = null): Request
    {
        return new Request(
            $method,
            $uri,
            [],
            [],
            [],
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            [],
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function inventoryHost(string $ip): array
    {
        $response = $this->app()->api()->handle($this->request('GET', '/api/inventory'));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        $hosts = array_column($body['hosts'], null, 'ip');
        self::assertArrayHasKey($ip, $hosts);
        return $hosts[$ip];
    }

    private function scanResult(array $ports, string $status = 'up'): array
    {
        return [
            'status' => $status,
            'duration' => 1,
            'ports' => $ports,
        ];
    }

    private function scanPort(int $port, string $service): array
    {
        return [
            'protocol' => 'tcp',
            'port' => $port,
            'state' => 'open',
            'service' => $service,
        ];
    }

    public function testNativeRouteManifestRemainsStable(): void
    {
        $actual = array_map(
            static fn(\FenPing\Api\Route $route): string => implode(' ', [
                $route->method, $route->pattern, $route->auth->value,
                implode(',', array_map(static fn(\FenPing\Realtime\LiveUpdateScope $scope): string => $scope->value, $route->liveScopes)),
            ]),
            $this->app()->api()->routes(),
        );
        $expected = [
            'GET /doctor session ', 'POST /networks/refresh guest ',
            'GET /dns/groups guest ', 'POST /dns/groups session dns',
            'PUT /dns/groups/{id:int} session dns', 'DELETE /dns/groups/{id:int} session dns',
            'GET /auth/session guest ', 'POST /auth/login guest ', 'POST /auth/logout guest ',
            'GET /audit session ',
            'GET /health guest ', 'GET /health/live guest ', 'GET /health/ready guest ',
            'GET /inventory guest ', 'PUT /inventory/device-metadata body hosts',
            'POST /inventory/saved-filters body hosts', 'PUT /inventory/saved-filters/{id:int} body hosts',
            'DELETE /inventory/saved-filters/{id:int} body hosts', 'GET /notify guest ',
            'GET /notify/telegram/chats session ', 'PUT /notify/delivery session all',
            'POST /ping/refresh session ', 'GET /exports/{dataset} session ', 'GET /history/{ip:ipv4} guest ',
            'GET /hosts/{id:int}/detail guest ', 'GET /hosts/by-ip/{ip:ipv4}/detail guest ',
            'GET /hosts/{id:int} guest ', 'POST /hosts body hosts', 'PUT /hosts/{id:int}/metadata body hosts',
            'PUT /hosts/{id:int} body hosts', 'DELETE /hosts/{id:int} body hosts',
            'POST /categories body hosts', 'PUT /categories body hosts', 'DELETE /categories body hosts',
            'GET /ipam guest ', 'GET /ipam/conflicts guest ',
            'PUT /ipam/devices/{mac}/approval session hosts', 'DELETE /ipam/devices/{mac}/approval session hosts',
            'GET /netboot/images guest ', 'POST /netboot/images session netboot',
            'GET /netboot/images/{id:int} guest ', 'GET /netboot/images/{id:int}/file guest ',
            'DELETE /netboot/images/{id:int} session netboot,hosts', 'GET /backups session ',
            'POST /backups session ', 'POST /backups/{filename}/restore session ',
            'GET /backups/{filename}/file session ', 'GET /scans guest ', 'GET /scans/profiles guest ',
            'GET /services guest ', 'POST /scans/{ip:ipv4} session scans',
            'POST /scans/{ip:ipv4}/quick session scans', 'GET /scans/{ip:ipv4}/status guest ',
            'POST /scans/{ip:ipv4}/{id:int}/cancel session ', 'GET /scans/{ip:ipv4}/history guest ',
            'GET /scans/{ip:ipv4}/history/{id:int} guest ', 'GET /scans/{ip:ipv4}/history/{id:int}/xml guest ',
            'GET /scans/{ip:ipv4}/xml guest ', 'GET /scans/{ip:ipv4} guest ',
            'GET /scans/{file:scanXml} guest ', 'GET /scans/{ip:ipv4}/{id:int} guest ',
            'GET /scans/{ip:ipv4}/{file:scanIdXml} guest ', 'GET /topology guest ',
        ];
        self::assertSame($expected, $actual);
    }
}
