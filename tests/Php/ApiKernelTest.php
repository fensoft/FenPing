<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;

final class ApiKernelTest extends IntegrationTestCase
{
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

    private function request(string $method, string $uri): Request
    {
        return new Request($method, $uri, [], [], [], ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri], []);
    }
}
