<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;

final class ApiKernelTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
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

        $request = new Request('GET', '/api/inventory?network=203.0.113.0%2F24', ['network' => '203.0.113.0/24'], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/inventory'], []);
        $unknown = $this->app()->api()->handle($request);
        self::assertSame(400, $unknown->status);
    }

    public function testIpamReturnsEveryConfiguredSubnetAndItsObservedDevices(): void
    {
        $this->app()->backend()->savePingHosts([
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

    private function request(string $method, string $uri): Request
    {
        return new Request($method, $uri, [], [], [], ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri], []);
    }
}
