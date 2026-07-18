<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;
use FenPing\Dns\DnsOverrideParser;
use InvalidArgumentException;

final class DnsOverrideTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
        if ($this->app()->auth()->isAuthenticated()) {
            $this->app()->auth()->logout();
        }
    }

    public function testHostsFileAndCnameRecordsCompileIntoDnsmasqConfiguration(): void
    {
        $parser = new DnsOverrideParser();
        $compiled = $parser->compile([[
            'id' => 1,
            'name' => 'Lab services',
            'enabled' => true,
            'contents' => "# imported hosts\n192.0.2.20 App.Example.test app # inline\nCNAME portal.example.test app.example.test\n",
        ]], ['fenping.lan', 'app.example.test']);

        self::assertSame(
            "host-record=app.example.test,app,192.0.2.20\ncname=portal.example.test,app.example.test\n",
            $compiled['config'],
        );
        self::assertSame(
            ['app.example.test', 'app', 'portal.example.test'],
            $compiled['owned_names'],
        );
        self::assertSame([1 => 2], $compiled['record_counts']);
    }

    public function testParserRejectsAmbiguousNamesCyclesAndUpstreamOnlyTargets(): void
    {
        $parser = new DnsOverrideParser();
        $base = [['id' => 1, 'name' => 'One', 'enabled' => true, 'contents' => "192.0.2.20 app.test\n"]];

        foreach ([
            [...$base, ['id' => 2, 'name' => 'Two', 'enabled' => true, 'contents' => "192.0.2.21 APP.TEST\n"]],
            [['id' => 1, 'name' => 'Cycle', 'enabled' => true, 'contents' => "CNAME one.test two.test\nCNAME two.test one.test\n"]],
            [['id' => 1, 'name' => 'External', 'enabled' => true, 'contents' => "CNAME local.test upstream.example\n"]],
        ] as $groups) {
            try {
                $parser->compile($groups, []);
                self::fail('Invalid DNS override configuration was accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testServiceStoresGroupsAndRendererSuppressesOverriddenBuiltInNames(): void
    {
        $database = $this->app()->database()->connection();
        $database->exec("INSERT INTO ips (name, mac, ip) VALUES ('printer', '02:00:00:00:00:20', '192.0.2.20')");
        $group = $this->app()->dnsOverrideGroups()->create([
            'name' => 'Printers',
            'enabled' => true,
            'contents' => "192.0.2.55 printer printer.lan\nCNAME print.lan printer.lan\n",
        ]);

        self::assertSame('Printers', $group['name']);
        self::assertSame(2, $group['record_count']);
        $files = $this->app()->dhcpConfig()->render();
        self::assertStringContainsString('host-record=printer,printer.lan,192.0.2.55', $files['customDns']);
        self::assertStringContainsString('cname=print.lan,printer.lan', $files['customDns']);
        self::assertStringNotContainsString('192.0.2.20 printer printer.lan', $files['dnsHosts']);
    }

    public function testGroupsAreGuestReadableButMutationsRequireLogin(): void
    {
        $guestCreate = $this->app()->api()->handle($this->request(
            'POST',
            '/api/dns/groups',
            ['name' => 'Blocked', 'enabled' => true, 'contents' => '192.0.2.20 blocked.test'],
        ));
        self::assertSame(403, $guestCreate->status);

        $list = $this->app()->api()->handle($this->request('GET', '/api/dns/groups'));
        self::assertSame(200, $list->status);
        self::assertSame(['groups' => []], json_decode($list->body, true));
    }

    private function request(string $method, string $uri, array $body = []): Request
    {
        return new Request(
            $method,
            $uri,
            [],
            [],
            [],
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            [],
            $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
