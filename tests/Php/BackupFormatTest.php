<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Backup\BackupService;
use RuntimeException;

final class BackupFormatTest extends IntegrationTestCase
{
    public function testBackupVersionAndArchiveContractsRemainCompatible(): void
    {
        $service = new BackupService($this->app()->backend(), $this->app()->config(), $this->app()->database());
        $service->validateDocument(['format' => 'fenping-db', 'version' => '1.6'], 'fenping-db', 'test.json');
        $service->validateDocument(['format' => 'fenping-db', 'version' => '1.99'], 'fenping-db', 'test.json');

        foreach (['1.5', '2.0'] as $version) {
            try {
                $service->validateDocument(['format' => 'fenping-db', 'version' => $version], 'fenping-db', 'test.json');
                self::fail('unsupported backup version was accepted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        self::assertTrue($service->archiveEntryIsSafe('./db.json'));
        self::assertFalse($service->archiveEntryIsSafe('../db.json'));
        self::assertFalse($service->archiveEntryIsSafe('/db.json'));

        $root = dirname(__DIR__, 2);
        $manifest = $service->readJson($root . '/demo/manifest.json', 'manifest.json');
        $databasePath = $root . '/demo/db.json';
        $database = $service->readJson($databasePath, 'db.json');
        $service->validateDocument($manifest, 'fenping-backup', 'manifest.json');
        $service->validateDocument($database, 'fenping-db', 'db.json');
        self::assertSame($manifest['version'], $database['version']);
        self::assertSame(filesize($databasePath), $manifest['database']['bytes']);
    }
}
