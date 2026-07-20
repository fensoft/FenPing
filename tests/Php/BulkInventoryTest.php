<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;
final class BulkInventoryTest extends IntegrationTestCase
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

    public function testBulkActionsRequireAnAuthenticatedSession(): void
    {
        $response = $this->app()->api()->handle($this->request([
            'action' => 'approve',
            'targets' => [['kind' => 'observed', 'mac' => '02:00:00:00:00:90']],
        ]));

        self::assertSame(403, $response->status);
        self::assertSame(0, (int) $this->app()->database()->connection()
            ->query('SELECT COUNT(*) FROM device_approvals')->fetchColumn());
    }

    public function testBulkTagsUpdateManagedAndDockerMetadataWhileSkippingAnonymousRows(): void
    {
        $backend = $this->app();
        $hostId = (int) $backend->hosts()->create('192.0.2.40', '02:00:00:00:00:40');
        $backend->hostMetadata()->replaceHostTags($hostId, ['Core', 'Old']);
        $this->installDockerDevice();
        $backend->hostMetadata()->saveInventoryDeviceMetadata('app_default', 'camera', [
            'tags' => ['Docker', 'Old'],
        ]);
        self::assertTrue($backend->auth()->login(''));

        $response = $backend->api()->handle($this->request([
            'action' => 'tags',
            'targets' => [
                ['kind' => 'host', 'id' => $hostId],
                ['kind' => 'device', 'network' => 'app_default', 'container' => 'camera'],
                ['kind' => 'observed', 'mac' => '02:00:00:00:00:99'],
            ],
            'add_tags' => [' New ', 'new'],
            'remove_tags' => ['old'],
        ]));

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(3, $body['requested_count']);
        self::assertSame(2, $body['changed_count']);
        self::assertSame(0, $body['unchanged_count']);
        self::assertSame(1, $body['skipped_count']);
        self::assertSame('not_metadata_editable', $body['skipped'][0]['reason']);
        self::assertSame(['Core', 'New'], $backend->hosts()->byId($hostId)['tags']);
        self::assertSame(
            ['Docker', 'New'],
            $backend->hostMetadata()->inventoryDeviceMetadata('app_default', 'camera')['tags'],
        );
        $dockerInventory = $backend->inventory()->forNetwork('198.51.100.0/24');
        self::assertSame(
            ['app_default-camera', 'Docker', 'New'],
            $dockerInventory[0]['tags'],
            'automatic Docker tags remain visible but are not replaced by the bulk edit',
        );
        self::assertSame(
            'inventory.bulk_tags',
            $backend->database()->connection()->query('SELECT action FROM audit_events ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
    }

    public function testBulkScanProfilePreservesCadenceAndReportsNoOps(): void
    {
        $backend = $this->app();
        $hostId = (int) $backend->hosts()->create('192.0.2.41', '02:00:00:00:00:41');
        $backend->database()->connection()->exec("UPDATE ips SET scan_interval_hours=12 WHERE id=$hostId");
        $this->installDockerDevice();
        $backend->hostMetadata()->saveInventoryDeviceMetadata('app_default', 'camera', [
            'scan_profile' => 'deep',
            'scan_interval_hours' => 6,
        ]);
        self::assertTrue($backend->auth()->login(''));

        $response = $backend->api()->handle($this->request([
            'action' => 'scan_profile',
            'targets' => [
                ['kind' => 'host', 'id' => $hostId],
                ['kind' => 'device', 'network' => 'app_default', 'container' => 'camera'],
                ['kind' => 'observed', 'ip' => '198.51.100.99'],
            ],
            'scan_profile' => 'deep',
        ]));

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['changed_count']);
        self::assertSame(1, $body['unchanged_count']);
        self::assertSame(1, $body['skipped_count']);
        $host = $backend->hosts()->byId($hostId);
        self::assertSame('deep', $host['scan_profile']);
        self::assertSame(12, (int) $host['scan_interval_hours']);
        $device = $backend->hostMetadata()->inventoryDeviceMetadata('app_default', 'camera');
        self::assertSame('deep', $device['scan_profile']);
        self::assertSame(6, $device['scan_interval_hours']);

        $invalid = $backend->api()->handle($this->request([
            'action' => 'scan_profile',
            'targets' => [['kind' => 'host', 'id' => $hostId]],
            'scan_profile' => 'quick',
        ]));
        self::assertSame(400, $invalid->status);
    }

    public function testBulkApprovalIsIdempotentAndDoesNotCreateReservations(): void
    {
        $backend = $this->app();
        $managedId = (int) $backend->hosts()->create('192.0.2.42', '02:00:00:00:00:42');
        self::assertTrue($backend->auth()->login(''));
        $payload = [
            'action' => 'approve',
            'targets' => [
                ['kind' => 'observed', 'mac' => '02-00-00-00-00-90'],
                ['kind' => 'host', 'id' => $managedId],
                ['kind' => 'observed', 'ip' => '192.0.2.91'],
            ],
        ];

        $first = json_decode($backend->api()->handle($this->request($payload))->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $first['changed_count']);
        self::assertSame(2, $first['skipped_count']);
        self::assertSame(1, (int) $backend->database()->connection()
            ->query('SELECT COUNT(*) FROM device_approvals')->fetchColumn());
        self::assertSame(1, (int) $backend->database()->connection()
            ->query("SELECT COUNT(*) FROM ips WHERE id=$managedId")->fetchColumn());

        $second = json_decode($backend->api()->handle($this->request($payload))->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $second['changed_count']);
        self::assertSame(1, $second['unchanged_count']);
        self::assertSame(2, $second['skipped_count']);
    }

    public function testBulkDeleteSkipsEveryNonDhcpTargetWithoutRegeneratingConfiguration(): void
    {
        $backend = $this->app();
        $hostId = (int) $backend->hosts()->create('198.51.100.42', '02:00:00:00:01:42');
        self::assertTrue($backend->auth()->login(''));

        $response = $backend->api()->handle($this->request([
            'action' => 'delete',
            'targets' => [
                ['kind' => 'host', 'id' => $hostId],
                ['kind' => 'observed', 'mac' => '02:00:00:00:01:43'],
            ],
        ]));

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['changed_count']);
        self::assertSame(2, $body['skipped_count']);
        self::assertIsArray($backend->hosts()->byId($hostId));
    }

    private function installDockerDevice(): void
    {
        $this->app()->dockerNetworks()->replace([[
            'cidr' => '198.51.100.0/24',
            'names' => ['app_default'],
            'containers' => [[
                'network' => 'app_default',
                'container' => 'camera',
                'ip' => '198.51.100.20',
            ]],
        ]], time());
        $this->app()->pingRepository()->save([[
            'ip' => '198.51.100.20',
            'mac' => '',
            'status' => 'Up',
        ]]);
    }

    private function request(array $body): Request
    {
        return new Request(
            'POST',
            '/api/inventory/bulk-actions',
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/inventory/bulk-actions'],
            [],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
