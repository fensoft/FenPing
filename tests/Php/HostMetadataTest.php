<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;
use InvalidArgumentException;
use PDO;

final class HostMetadataTest extends IntegrationTestCase
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

    public function testMetadataTagsGuestReadsClearingAndHostCascade(): void
    {
        $backend = $this->app();
        $id = (int) $backend->hosts()->create('192.0.2.40', '02:00:00:00:00:40');
        $backend->hosts()->update(
            $id,
            '192.0.2.40',
            '02:00:00:00:00:40',
            'Core node',
            null,
            '1',
            null,
            '',
            '',
            null,
            'standard',
            24,
            $backend->hostMetadataNormalizer()->notes("Line one\r\nLine two"),
            'Rack 4',
            'Alice',
            'PowerEdge R740',
            'server',
            $backend->hostMetadataNormalizer()->tags([' Server ', 'server', '', 'Camera']),
        );

        $host = $backend->hosts()->byId($id);
        self::assertIsArray($host);
        self::assertSame("Line one\nLine two", $host['notes']);
        self::assertSame('Rack 4', $host['location']);
        self::assertSame('Alice', $host['owner']);
        self::assertSame('PowerEdge R740', $host['model']);
        self::assertSame('server', $host['icon']);
        self::assertSame(['Camera', 'Server'], $host['tags']);
        $backend->pingRepository()->save([
            ['ip' => '192.0.2.40', 'mac' => '02:00:00:00:00:40', 'status' => 'Up'],
        ]);

        $response = $this->app()->api()->handle($this->request('GET', "/api/hosts/$id"));
        self::assertSame(200, $response->status);
        $guestHost = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($host['notes'], $guestHost['notes']);
        self::assertSame($host['tags'], $guestHost['tags']);

        $inventory = $this->app()->api()->handle($this->request('GET', '/api/inventory'));
        self::assertSame(200, $inventory->status);
        $inventoryBody = json_decode($inventory->body, true, flags: JSON_THROW_ON_ERROR);
        $inventoryHosts = array_column($inventoryBody['hosts'], null, 'id');
        self::assertSame('Alice', $inventoryHosts[$id]['owner']);
        self::assertSame(['Camera', 'Server'], $inventoryHosts[$id]['tags']);
        self::assertSame(['Camera', 'Server'], $inventoryBody['available_tags']);

        $secondId = (int) $backend->hosts()->create('192.0.2.41', '02:00:00:00:00:41');
        $backend->hosts()->update(
            $secondId,
            '192.0.2.41',
            '02:00:00:00:00:41',
            'Print node',
            null,
            null,
            null,
            '',
            '',
            null,
            'standard',
            24,
            '',
            '',
            '',
            '',
            'printer',
            $backend->hostMetadataNormalizer()->tags(['SERVER', 'Printer']),
        );
        self::assertSame(['Printer', 'Server'], $backend->hosts()->byId($secondId)['tags']);

        $backend->hosts()->update(
            $id,
            '192.0.2.40',
            '02:00:00:00:00:40',
            'Core node',
            null,
            '1',
            null,
            '',
            '',
            null,
            'standard',
            24,
            '',
            '',
            '',
            '',
            null,
            [],
        );
        $cleared = $backend->hosts()->byId($id);
        self::assertSame('', $cleared['notes']);
        self::assertSame('', $cleared['location']);
        self::assertSame('', $cleared['owner']);
        self::assertSame('', $cleared['model']);
        self::assertNull($cleared['icon']);
        self::assertSame([], $cleared['tags']);

        $backend->hosts()->delete($secondId);
        $assignments = $this->app()->database()->connection()->prepare(
            'SELECT COUNT(*) FROM host_tags WHERE host_id=:host_id',
        );
        $assignments->execute(['host_id' => $secondId]);
        self::assertSame(0, (int) $assignments->fetchColumn());
        self::assertSame(
            [],
            $this->app()->database()->connection()->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function testDhcpMutationFailureRollsBackMetadataAndTags(): void
    {
        $backend = $this->app();
        $id = (int) $backend->hosts()->create('192.0.2.45', '02:00:00:00:00:45');
        $backend->hosts()->update(
            $id,
            '192.0.2.45',
            '02:00:00:00:00:45',
            'Rollback host',
            null,
            null,
            null,
            '',
            '',
            null,
            'standard',
            24,
            'Before',
            'Rack 1',
            'Alice',
            'Model A',
            'server',
            ['Stable'],
        );

        try {
            $backend->dhcpMutations()->commit(function () use ($backend, $id): void {
                $backend->hosts()->update(
                    $id,
                    '192.0.2.45',
                    '02:00:00:00:00:45',
                    'Rollback host',
                    null,
                    null,
                    null,
                    '',
                    '',
                    null,
                    'standard',
                    24,
                    'After',
                    'Rack 9',
                    'Bob',
                    'Model B',
                    'camera',
                    ['Changed'],
                );
                throw new \RuntimeException('forced DHCP coordination failure');
            });
            self::fail('DHCP coordination failure was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('forced DHCP coordination failure', $error->getMessage());
        }

        $host = $backend->hosts()->byId($id);
        self::assertIsArray($host);
        self::assertSame('Before', $host['notes']);
        self::assertSame('Rack 1', $host['location']);
        self::assertSame('Alice', $host['owner']);
        self::assertSame('Model A', $host['model']);
        self::assertSame('server', $host['icon']);
        self::assertSame(['Stable'], $host['tags']);
    }

    public function testBackupRestorePreservesMetadataTagsAndViewsAndAcceptsOlderDocuments(): void
    {
        $backend = $this->app();
        $id = (int) $backend->hosts()->create('192.0.2.44', '02:00:00:00:00:44');
        $backend->hosts()->update(
            $id,
            '192.0.2.44',
            '02:00:00:00:00:44',
            'Backup host',
            null,
            null,
            null,
            '',
            '',
            null,
            'standard',
            24,
            'Restored notes',
            'Lab',
            'Operations',
            'Model X',
            'database',
            ['Database', 'Server'],
        );
        $savedFilter = $backend->inventory()->createSavedFilter('Infrastructure', ['Server']);
        $savedDevice = $backend->hostMetadata()->saveInventoryDeviceMetadata('backup_default', 'postgres', [
            'display_name' => 'Container database',
            'scan_profile' => 'deep',
            'scan_interval_hours' => 12,
            'tags' => ['Container', 'Database'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'fenping-host-metadata-backup-');
        self::assertIsString($path);
        try {
            $backend->backupArchives()->backupWriteDatabaseJson($path);
            $document = $backend->backupTools()->backupReadJson($path, 'db.json');
            foreach ([
                'tags', 'host_tags', 'inventory_saved_filters', 'inventory_saved_filter_tags',
                'inventory_device_metadata', 'inventory_device_tags',
            ] as $table) {
                self::assertArrayHasKey($table, $document['tables']);
            }

            $this->resetDatabase();
            ob_start();
            try {
                $backend->backupDocuments()->backupRestoreDatabase($document);
            } finally {
                ob_end_clean();
            }
            $restored = $backend->hosts()->byId($id);
            self::assertIsArray($restored);
            self::assertSame('Restored notes', $restored['notes']);
            self::assertSame('Lab', $restored['location']);
            self::assertSame('Operations', $restored['owner']);
            self::assertSame('Model X', $restored['model']);
            self::assertSame('database', $restored['icon']);
            self::assertSame(['Database', 'Server'], $restored['tags']);
            self::assertSame(
                [$savedFilter],
                $backend->hostMetadata()->savedInventoryFilters(),
            );
            $restoredDevice = $backend->hostMetadata()->inventoryDeviceMetadata('backup_default', 'postgres');
            self::assertIsArray($restoredDevice);
            self::assertSame($savedDevice['display_name'], $restoredDevice['display_name']);
            self::assertSame($savedDevice['scan_profile'], $restoredDevice['scan_profile']);
            self::assertSame($savedDevice['tags'], $restoredDevice['tags']);

            $legacyDocument = [
                'tables' => [
                    'ips' => [
                        'columns' => ['id', 'name', 'mac', 'ip'],
                        'rows' => [[90, 'Legacy host', '02:00:00:00:00:90', '192.0.2.90']],
                    ],
                ],
            ];
            ob_start();
            try {
                $backend->backupDocuments()->backupRestoreDatabase($legacyDocument);
            } finally {
                ob_end_clean();
            }
            $legacy = $backend->hosts()->byId(90);
            self::assertIsArray($legacy);
            self::assertSame('', $legacy['notes']);
            self::assertSame('', $legacy['location']);
            self::assertSame('', $legacy['owner']);
            self::assertSame('', $legacy['model']);
            self::assertNull($legacy['icon']);
            self::assertSame([], $legacy['tags']);
            self::assertSame([], $backend->hostMetadata()->savedInventoryFilters());
            self::assertFalse($backend->hostMetadata()->inventoryDeviceMetadata('backup_default', 'postgres'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testNamedDockerDeviceMetadataRequiresVerifiedIdentityAndFollowsIpChanges(): void
    {
        $backend = $this->app();
        $backend->dockerNetworks()->replace([[
            'cidr' => '198.51.100.0/24',
            'names' => ['app_default'],
            'gateways' => [[
                'network' => 'app_default',
                'ip' => '198.51.100.1',
            ]],
            'containers' => [[
                'network' => 'app_default',
                'container' => 'camera',
                'ip' => '198.51.100.20',
            ]],
        ]], time());
        $backend->pingRepository()->save([
            ['ip' => '198.51.100.1', 'mac' => '', 'status' => 'Up'],
            ['ip' => '198.51.100.20', 'mac' => '', 'status' => 'Up'],
            ['ip' => '198.51.100.21', 'mac' => '', 'status' => 'Up'],
        ]);

        $inventory = $backend->inventory()->forNetwork('198.51.100.0/24');
        $named = array_values(array_filter(
            $inventory,
            static fn(array $host): bool => ($host['device_identity']['container'] ?? null) === 'camera',
        ));
        self::assertCount(1, $named);
        self::assertSame(1, $named[0]['metadata_editable']);
        self::assertSame(0, $named[0]['dhcp_managed']);
        self::assertSame(['app_default-camera'], $named[0]['tags']);
        self::assertSame([], $named[0]['stored_tags']);
        self::assertSame(['app_default-camera'], $named[0]['automatic_tags']);
        $gateway = array_values(array_filter(
            $inventory,
            static fn(array $host): bool => $host['ip'] === '198.51.100.1',
        ));
        self::assertCount(1, $gateway);
        self::assertSame('docker', $gateway[0]['name']);
        self::assertSame(['gateway'], $gateway[0]['tags']);
        self::assertSame([], $gateway[0]['stored_tags']);
        self::assertSame(['gateway'], $gateway[0]['automatic_tags']);
        self::assertSame(
            ['app_default-camera', 'gateway'],
            $backend->inventory()->availableTags(),
        );
        $anonymous = array_values(array_filter(
            $inventory,
            static fn(array $host): bool => $host['ip'] === '198.51.100.21',
        ));
        self::assertCount(1, $anonymous);
        self::assertSame(0, $anonymous[0]['metadata_editable']);
        self::assertNull($anonymous[0]['device_identity']);

        $payload = [
            'network' => 'app_default',
            'container' => 'camera',
            'display_name' => 'Loading dock camera',
            'important' => 1,
            'web' => 1,
            'scan_profile' => 'deep',
            'scan_interval_hours' => 6,
            'notes' => "North entrance\r\nPoE",
            'owner' => 'Security',
            'icon' => 'camera',
            'tags' => [' Camera ', 'camera', 'Outdoor'],
        ];
        $guest = $this->app()->api()->handle($this->request(
            'PUT',
            '/api/inventory/device-metadata',
            $payload,
        ));
        self::assertSame(403, $guest->status);

        self::assertTrue($this->app()->auth()->login(''));
        $operations = (int) $this->app()->database()->connection()
            ->query('SELECT COUNT(*) FROM operation_status')->fetchColumn();
        $savedResponse = $this->app()->api()->handle($this->request(
            'PUT',
            '/api/inventory/device-metadata',
            $payload,
        ));
        self::assertSame(200, $savedResponse->status);
        $saved = json_decode($savedResponse->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame("North entrance\nPoE", $saved['notes']);
        self::assertSame(['Camera', 'Outdoor'], $saved['tags']);
        self::assertSame(
            $operations,
            (int) $this->app()->database()->connection()
                ->query('SELECT COUNT(*) FROM operation_status')->fetchColumn(),
        );

        $partialResponse = $this->app()->api()->handle($this->request(
            'PUT',
            '/api/inventory/device-metadata',
            ['network' => 'app_default', 'container' => 'camera', 'notes' => ''],
        ));
        $partial = json_decode($partialResponse->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('', $partial['notes']);
        self::assertSame('Security', $partial['owner']);

        $backend->dockerNetworks()->replace([[
            'cidr' => '198.51.100.0/24',
            'names' => ['app_default'],
            'containers' => [[
                'network' => 'app_default',
                'container' => 'camera',
                'ip' => '198.51.100.22',
            ]],
        ]], time());
        $backend->pingRepository()->save([
            ['ip' => '198.51.100.22', 'mac' => '', 'status' => 'Up'],
        ]);
        $moved = array_values(array_filter(
            $backend->inventory()->forNetwork('198.51.100.0/24'),
            static fn(array $host): bool => ($host['device_identity']['container'] ?? null) === 'camera',
        ));
        self::assertCount(1, $moved);
        self::assertSame('198.51.100.22', $moved[0]['ip']);
        self::assertSame('Loading dock camera', $moved[0]['display_name']);

        $backend->dockerNetworks()->replace([], time());
        $stale = $this->app()->api()->handle($this->request(
            'PUT',
            '/api/inventory/device-metadata',
            ['network' => 'app_default', 'container' => 'camera', 'owner' => 'Nobody'],
        ));
        self::assertSame(409, $stale->status);
        self::assertIsArray($backend->hostMetadata()->inventoryDeviceMetadata('app_default', 'camera'));
    }

    public function testDiscoveryCadenceAndDhcpPromotionFollowIdentityAtomically(): void
    {
        $backend = $this->app();
        $backend->dockerNetworks()->replace([[
            'cidr' => '198.51.100.0/24',
            'names' => ['jobs_default'],
            'containers' => [[
                'network' => 'jobs_default',
                'container' => 'worker',
                'ip' => '198.51.100.50',
            ]],
        ]], time());
        $backend->hostMetadata()->saveInventoryDeviceMetadata('jobs_default', 'worker', [
            'scan_profile' => 'deep',
            'scan_interval_hours' => 0,
        ]);
        self::assertSame(
            [],
            $backend->inventory()->scheduledTargets(
                ['198.51.100.50'],
                1_800_000_000,
                '198.51.100.0/24',
            ),
        );
        $backend->hostMetadata()->saveInventoryDeviceMetadata('jobs_default', 'worker', [
            'scan_interval_hours' => 6,
        ]);
        self::assertSame(
            [['ip' => '198.51.100.50', 'profile' => 'deep']],
            $backend->inventory()->scheduledTargets(
                ['198.51.100.50'],
                1_800_000_000,
                '198.51.100.0/24',
            ),
        );

        $backend->hostMetadata()->saveInventoryDeviceMetadata('edge_default', 'gateway-ui', [
            'display_name' => 'Gateway UI',
            'important' => 1,
            'web' => 1,
            'scan_profile' => 'deep',
            'scan_interval_hours' => 4,
            'notes' => 'Transferred notes',
            'location' => 'Edge host',
            'owner' => 'Network team',
            'model' => 'Container v2',
            'icon' => 'router',
            'tags' => ['Docker', 'Gateway'],
        ]);
        $database = $this->app()->database();
        $database->beginImmediate();
        try {
            $hostId = (int) $backend->hosts()->create('192.0.2.60', '02:00:00:00:00:60');
            self::assertTrue($backend->hostMetadata()->transferInventoryDeviceMetadataToHost(
                'edge_default',
                'gateway-ui',
                $hostId,
            ));
            $database->commit();
        } catch (\Throwable $error) {
            $database->rollback();
            throw $error;
        }
        $host = $backend->hosts()->byId($hostId);
        self::assertIsArray($host);
        self::assertSame('Gateway UI', $host['display_name']);
        self::assertSame('Transferred notes', $host['notes']);
        self::assertSame('Network team', $host['owner']);
        self::assertSame('deep', $host['scan_profile']);
        self::assertSame(4, (int) $host['scan_interval_hours']);
        self::assertSame('router', $host['icon']);
        self::assertSame(['Docker', 'Gateway'], $host['tags']);
        self::assertFalse($backend->hostMetadata()->inventoryDeviceMetadata('edge_default', 'gateway-ui'));

        $backend->hostMetadata()->saveInventoryDeviceMetadata('edge_default', 'rollback', [
            'display_name' => 'Rollback source',
            'tags' => ['Stable'],
        ]);
        try {
            $backend->dhcpMutations()->commit(function () use ($backend): void {
                $id = (int) $backend->hosts()->create('192.0.2.61', '02:00:00:00:00:61');
                $backend->hostMetadata()->transferInventoryDeviceMetadataToHost('edge_default', 'rollback', $id);
                throw new \RuntimeException('forced promotion failure');
            });
            self::fail('promotion failure was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('forced promotion failure', $error->getMessage());
        }
        self::assertFalse($backend->hosts()->byIp('192.0.2.61'));
        $source = $backend->hostMetadata()->inventoryDeviceMetadata('edge_default', 'rollback');
        self::assertIsArray($source);
        self::assertSame('Rollback source', $source['display_name']);
        self::assertSame(['Stable'], $source['tags']);

        $backend->dockerNetworks()->replace([[
            'cidr' => '192.0.2.0/24',
            'names' => ['edge_default'],
            'containers' => [[
                'network' => 'edge_default',
                'container' => 'rollback',
                'ip' => '192.0.2.61',
            ]],
        ]], time());
        self::assertTrue($this->app()->auth()->login(''));
        $mismatch = $this->app()->api()->handle($this->request('POST', '/api/hosts', [
            'ip' => '192.0.2.62',
            'mac' => '02:00:00:00:00:62',
            'source_device' => ['network' => 'edge_default', 'container' => 'rollback'],
        ]));
        self::assertSame(409, $mismatch->status);
        self::assertIsArray($backend->hostMetadata()->inventoryDeviceMetadata('edge_default', 'rollback'));
    }

    public function testOverlappingDockerAddressesUseNetworkAndContainerForDetail(): void
    {
        $backend = $this->app();
        $backend->dockerNetworks()->replace([[
            'cidr' => '198.51.100.0/24',
            'names' => ['blue', 'green'],
            'containers' => [
                ['network' => 'blue', 'container' => 'api', 'ip' => '198.51.100.30'],
                ['network' => 'green', 'container' => 'api', 'ip' => '198.51.100.30'],
            ],
        ]], time());
        $backend->pingRepository()->save([
            ['ip' => '198.51.100.30', 'mac' => '', 'status' => 'Up'],
        ]);
        $backend->hostMetadata()->saveInventoryDeviceMetadata('blue', 'api', [
            'display_name' => 'Blue API',
        ]);
        $backend->hostMetadata()->saveInventoryDeviceMetadata('green', 'api', [
            'display_name' => 'Green API',
        ]);

        $rows = array_values(array_filter(
            $backend->inventory()->forNetwork('198.51.100.0/24'),
            static fn(array $host): bool => $host['ip'] === '198.51.100.30',
        ));
        self::assertCount(2, $rows);
        self::assertSame(['Blue API', 'Green API'], array_column($rows, 'display_name'));

        $response = $this->app()->api()->handle($this->request(
            'GET',
            '/api/hosts/by-ip/198.51.100.30/detail',
            null,
            ['network' => 'green', 'container' => 'api'],
        ));
        self::assertSame(200, $response->status);
        $detail = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Green API', $detail['host']['display_name']);
        self::assertSame(
            ['network' => 'green', 'container' => 'api'],
            $detail['host']['device_identity'],
        );
        self::assertSame(0, $detail['host']['dhcp_managed']);
    }


    public function testNamedNonDhcpManagedRecordHasMetadataOnlyEditing(): void
    {
        $backend = $this->app();
        $id = (int) $backend->hosts()->create('198.51.100.40', '02:00:00:00:01:40');
        $backend->hosts()->update(
            $id,
            '198.51.100.40',
            '02:00:00:00:01:40',
            'edge-service',
            null,
            null,
            null,
            '',
            '',
            null,
            'standard',
            24,
            'Original notes',
        );
        $backend->pingRepository()->save([[
            'ip' => '198.51.100.40',
            'mac' => '02:00:00:00:01:40',
            'status' => 'Up',
        ]]);

        $inventory = $backend->inventory()->forNetwork('198.51.100.0/24');
        $row = array_values(array_filter(
            $inventory,
            static fn(array $host): bool => (int)($host['id'] ?? 0) === $id,
        ))[0];
        self::assertSame(0, $row['network_is_dhcp']);
        self::assertSame(1, $row['dhcp_managed']);
        self::assertSame(1, $row['metadata_editable']);
        self::assertSame(
            ['network' => '198.51.100.0/24', 'container' => 'edge-service'],
            $row['device_identity'],
        );

        $detailResponse = $this->app()->api()->handle($this->request(
            'GET',
            "/api/hosts/$id/detail",
        ));
        self::assertSame(200, $detailResponse->status);
        $detail = json_decode($detailResponse->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $detail['host']['metadata_editable']);
        self::assertSame($row['device_identity'], $detail['host']['device_identity']);

        $guest = $this->app()->api()->handle($this->request(
            'PUT',
            "/api/hosts/$id/metadata",
            ['display_name' => 'Guest write'],
        ));
        self::assertSame(403, $guest->status);

        self::assertTrue($this->app()->auth()->login(''));
        $forbidden = $this->app()->api()->handle($this->request(
            'PUT',
            "/api/hosts/$id/metadata",
            ['ip' => '198.51.100.41'],
        ));
        self::assertSame(400, $forbidden->status);
        self::assertSame(
            ['error' => 'ip is DHCP-only'],
            json_decode($forbidden->body, true, flags: JSON_THROW_ON_ERROR),
        );

        $operations = (int) $this->app()->database()->connection()
            ->query('SELECT COUNT(*) FROM operation_status')->fetchColumn();
        $savedResponse = $this->app()->api()->handle($this->request(
            'PUT',
            "/api/hosts/$id/metadata",
            [
                'display_name' => 'Remote edge',
                'important' => 1,
                'web' => 1,
                'scan_profile' => 'deep',
                'scan_interval_hours' => 0,
                'notes' => "Line one\r\nLine two",
                'location' => 'Remote rack',
                'owner' => 'Platform',
                'model' => 'Edge 2000',
                'icon' => 'server',
                'tags' => [' Remote ', 'remote', 'Server'],
            ],
        ));
        self::assertSame(200, $savedResponse->status);
        self::assertSame(
            $operations,
            (int) $this->app()->database()->connection()
                ->query('SELECT COUNT(*) FROM operation_status')->fetchColumn(),
        );

        $saved = $backend->hosts()->byId($id);
        self::assertIsArray($saved);
        self::assertSame('198.51.100.40', $saved['ip']);
        self::assertSame('02:00:00:00:01:40', $saved['mac']);
        self::assertSame('edge-service', $saved['name']);
        self::assertSame('Remote edge', $saved['display_name']);
        self::assertSame(1, (int) $saved['important']);
        self::assertSame(1, (int) $saved['web']);
        self::assertSame('deep', $saved['scan_profile']);
        self::assertSame(0, (int) $saved['scan_interval_hours']);
        self::assertSame("Line one\nLine two", $saved['notes']);
        self::assertSame('Remote rack', $saved['location']);
        self::assertSame('Platform', $saved['owner']);
        self::assertSame('Edge 2000', $saved['model']);
        self::assertSame('server', $saved['icon']);
        self::assertSame(['Remote', 'Server'], $saved['tags']);
        self::assertSame(
            [],
            $backend->inventory()->scheduledTargets(
                ['198.51.100.40'],
                1_800_000_000,
                '198.51.100.0/24',
            ),
        );

        $unnamedId = (int) $backend->hosts()->create('198.51.100.41', '02:00:00:00:01:41');
        $backend->pingRepository()->save([[
            'ip' => '198.51.100.41',
            'mac' => '02:00:00:00:01:41',
            'status' => 'Up',
        ]]);
        $unnamed = array_values(array_filter(
            $backend->inventory()->forNetwork('198.51.100.0/24'),
            static fn(array $host): bool => (int)($host['id'] ?? 0) === $unnamedId,
        ))[0];
        self::assertSame(0, $unnamed['metadata_editable']);
        self::assertNull($unnamed['device_identity']);

        $unnamedWrite = $this->app()->api()->handle($this->request(
            'PUT',
            "/api/hosts/$unnamedId/metadata",
            ['notes' => 'Not allowed'],
        ));
        self::assertSame(409, $unnamedWrite->status);
    }
    public function testInvalidIconsAreRejectedBeforeAuthenticatedHostMutation(): void
    {
        $backend = $this->app();
        self::assertSame('printer', $backend->hostMetadataNormalizer()->icon('printer'));
        $this->expectException(InvalidArgumentException::class);
        $backend->hostMetadataNormalizer()->icon('user-supplied-svg');
    }

    public function testGuestCannotWriteMetadataAndAuthenticatedInvalidIconReturnsBadRequest(): void
    {
        $id = (int) $this->app()->hosts()->create('192.0.2.43', '02:00:00:00:00:43');
        $body = [
            'ip' => '192.0.2.43',
            'mac' => '02:00:00:00:00:43',
            'name' => 'Camera',
            'icon' => 'unknown',
            'tags' => ['Camera'],
        ];
        $guest = $this->app()->api()->handle($this->request('PUT', "/api/hosts/$id", $body));
        self::assertSame(403, $guest->status);

        self::assertTrue($this->app()->auth()->login(''));
        $invalid = $this->app()->api()->handle($this->request('PUT', "/api/hosts/$id", $body));
        self::assertSame(400, $invalid->status);
        self::assertSame(['error' => 'invalid host icon'], json_decode($invalid->body, true));
        self::assertNull($this->app()->hosts()->byId($id)['icon']);
    }

    private function request(
        string $method,
        string $uri,
        ?array $body = null,
        array $query = [],
    ): Request
    {
        return new Request(
            $method,
            $uri,
            $query,
            [],
            [],
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            [],
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
