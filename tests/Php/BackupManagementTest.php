<?php

declare(strict_types=1);

namespace FenPing\Tests;

use DateTimeImmutable;
use FenPing\Api\FileResponse;
use FenPing\Api\Request;
use FenPing\Backup\BackupManager;
use ReflectionMethod;

final class BackupManagementTest extends IntegrationTestCase
{
    private string $archive;
    /** @var list<string> */
    private array $generatedArchives = [];

    protected function setUp(): void
    {
        $this->resetDatabase();
        $this->archive = $this->app()->config()->backupDir() . '/test-managed.tgz';
        $this->generatedArchives = [];
        @unlink($this->archive);
        @unlink($this->archive . '.metadata.json');
    }

    protected function tearDown(): void
    {
        @unlink($this->archive);
        @unlink($this->archive . '.metadata.json');
        foreach ($this->generatedArchives as $archive) {
            @unlink($archive);
            @unlink($archive . '.metadata.json');
        }
        if ($this->app()->auth()->isAuthenticated()) {
            $this->app()->auth()->logout();
        }
    }

    public function testVerificationRestoresOnlyIntoTemporaryDataAndRecordsMetadata(): void
    {
        $database = $this->app()->database()->connection();
        $database->exec("INSERT INTO ips (name, ip) VALUES ('before backup', '192.0.2.10')");
        self::assertSame(0, $this->app()->backups()->backup([$this->archive]));
        $database->exec("INSERT INTO ips (name, ip) VALUES ('live after backup', '192.0.2.11')");

        self::assertSame(0, $this->app()->backups()->verify([$this->archive]));
        self::assertSame(2, (int) $database->query('SELECT COUNT(*) FROM ips')->fetchColumn());

        $metadata = json_decode((string) file_get_contents($this->archive . '.metadata.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('verified', $metadata['verification']['status']);
        self::assertSame(hash_file('sha256', $this->archive), $metadata['sha256']);

        $listed = $this->app()->backups()->apiList();
        $record = array_values(array_filter($listed['backups'], fn(array $item): bool => $item['filename'] === basename($this->archive)))[0];
        self::assertSame('verified', $record['verification']['status']);
        self::assertTrue($listed['storage']['same_filesystem']);
    }

    public function testCorruptArchiveIsMarkedFailed(): void
    {
        self::assertSame(0, $this->app()->backups()->backup([$this->archive]));
        file_put_contents($this->archive, 'not a gzip archive');
        self::assertSame(1, $this->app()->backups()->verify([$this->archive]));
        $metadata = json_decode((string) file_get_contents($this->archive . '.metadata.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('failed', $metadata['verification']['status']);
        self::assertNotSame('', $metadata['verification']['message']);
    }

    public function testBackupApiRequiresLoginAndStreamsOnlyValidatedArchives(): void
    {
        $guest = $this->app()->api()->handle($this->request('/api/backups'));
        self::assertSame(403, $guest->status);
        $guestDownload = $this->app()->api()->handle($this->request('/api/backups/' . rawurlencode(basename($this->archive)) . '/file'));
        self::assertSame(403, $guestDownload->status);

        self::assertTrue($this->app()->auth()->login(''));
        self::assertSame(0, $this->app()->backups()->backup([$this->archive]));
        $list = $this->app()->api()->handle($this->request('/api/backups'));
        self::assertSame(200, $list->status);
        $download = $this->app()->api()->handle($this->request('/api/backups/' . rawurlencode(basename($this->archive)) . '/file'));
        self::assertInstanceOf(FileResponse::class, $download);
        self::assertStringContainsString('attachment;', $download->headers['Content-Disposition']);

        $sidecar = $this->app()->api()->handle($this->request('/api/backups/' . rawurlencode(basename($this->archive) . '.metadata.json') . '/file'));
        self::assertSame(400, $sidecar->status);
    }

    public function testBackupApiCreatesAndRestoresWithSafetyBackup(): void
    {
        $guestCreate = $this->app()->api()->handle($this->request('/api/backups', 'POST'));
        self::assertSame(403, $guestCreate->status);
        $guestRestore = $this->app()->api()->handle($this->request('/api/backups/missing.tgz/restore', 'POST'));
        self::assertSame(403, $guestRestore->status);

        self::assertTrue($this->app()->auth()->login(''));
        $database = $this->app()->database()->connection();
        $database->exec("INSERT INTO ips (name, ip) VALUES ('before backup', '192.0.2.10')");

        $create = $this->app()->api()->handle($this->request('/api/backups', 'POST'));
        self::assertSame(200, $create->status, $create->body);
        $created = json_decode($create->body, true, flags: JSON_THROW_ON_ERROR)['created'];
        self::assertMatchesRegularExpression('/^fenping-manual-\d{8}-\d{6}-[a-f0-9]{6}\.tgz$/', $created);
        $createdPath = $this->app()->config()->backupDir() . '/' . $created;
        $this->generatedArchives[] = $createdPath;
        self::assertFileExists($createdPath);

        $database->exec("INSERT INTO ips (name, ip) VALUES ('after backup', '192.0.2.11')");
        $restore = $this->app()->api()->handle($this->request('/api/backups/' . rawurlencode($created) . '/restore', 'POST'));
        self::assertSame(200, $restore->status, $restore->body);
        $restored = json_decode($restore->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($created, $restored['restored']);
        self::assertNotSame($created, $restored['safety_backup']);
        $safetyPath = $this->app()->config()->backupDir() . '/' . $restored['safety_backup'];
        $this->generatedArchives[] = $safetyPath;
        self::assertFileExists($safetyPath);
        self::assertSame(1, (int) $database->query('SELECT COUNT(*) FROM ips')->fetchColumn());
        self::assertSame('before backup', $database->query('SELECT name FROM ips')->fetchColumn());
    }

    public function testRetentionSelectsSevenDaysFourIsoWeeksAndTwoCheckpoints(): void
    {
        $now = new DateTimeImmutable('2026-07-12T12:00:00+00:00');
        $records = [];
        for ($days = 0; $days < 35; $days++) {
            $created = $now->modify("-$days days");
            $records[] = [
                'filename' => 'daily-' . $created->format('Ymd') . '.tgz',
                'kind' => 'daily',
                'created_at' => $created->format(DATE_ATOM),
                'verification' => ['status' => 'verified'],
            ];
        }
        for ($index = 0; $index < 3; $index++) {
            $records[] = [
                'filename' => "checkpoint-$index.tgz",
                'kind' => 'pre-upgrade',
                'created_at' => $now->modify("-$index hours")->format(DATE_ATOM),
                'verification' => ['status' => 'verified'],
            ];
        }
        $manager = $this->app()->backupManager();
        $method = new ReflectionMethod($manager, 'retentionRoles');
        $roles = $method->invoke($manager, $records, $now);
        $daily = array_filter($roles, static fn(array $items): bool => in_array('daily', $items, true));
        $weekly = array_filter($roles, static fn(array $items): bool => in_array('weekly', $items, true));
        $checkpoints = array_filter($roles, static fn(array $items): bool => in_array('checkpoint', $items, true));
        self::assertCount(7, $daily);
        self::assertCount(4, $weekly);
        self::assertCount(2, $checkpoints);
        self::assertArrayNotHasKey('checkpoint-2.tgz', $roles);
    }

    private function request(string $uri, string $method = 'GET'): Request
    {
        return new Request($method, $uri, [], [], [], ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri], []);
    }
}
