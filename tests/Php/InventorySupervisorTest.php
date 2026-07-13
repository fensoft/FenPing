<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Application;
use FenPing\Backend\InventoryCancelledException;
use FenPing\Backend\InventoryTimeoutException;
use FenPing\Realtime\LiveUpdateScope;
use RuntimeException;

final class InventorySupervisorTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testSupervisorParsesProgressAndFinalizesWithoutLanTraffic(): void
    {
        $publisher = new RecordingLiveUpdatePublisher();
        $app = Application::forConfig($this->app()->config(), $publisher);
        $id = $app->scanJobs()->start('192.0.2.50', 'standard');
        $publisher->events = [];

        $command = $app->backend()->inventoryScanCommand('192.0.2.50', 'standard', '/tmp/synthetic.xml');
        $statsIndex = array_search('--stats-every', $command, true);
        self::assertIsInt($statsIndex);
        self::assertSame('5s', $command[$statsIndex + 1]);

        $app->backend()->inventoryRunScanProcess([
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, "SYN Stealth Scan Timing: About 50.00% done; ETC: 12:00\n");',
        ], 5, $id, 'standard');

        $metadata = $app->scanJobs()->findJob($id);
        self::assertSame(99, $metadata['progress_percent']);
        self::assertSame('finalizing', $metadata['progress_phase']);
        self::assertSame([
            [LiveUpdateScope::Scans],
            [LiveUpdateScope::Scans],
        ], $publisher->events);
    }

    public function testSupervisorEnforcesPhpTimeout(): void
    {
        $id = $this->app()->scanJobs()->start('192.0.2.51', 'lightweight');
        $started = microtime(true);
        try {
            $this->app()->backend()->inventoryRunScanProcess(
                [PHP_BINARY, '-r', 'sleep(5);'],
                1,
                $id,
                'lightweight',
            );
            self::fail('synthetic process did not time out');
        } catch (InventoryTimeoutException $error) {
            self::assertStringContainsString('timed out after 1 second', $error->getMessage());
            self::assertLessThan(4.0, microtime(true) - $started);
        }
    }

    public function testCancellationIsCheckedBeforeStartingProcess(): void
    {
        $marker = sys_get_temp_dir() . '/fenping-supervisor-' . bin2hex(random_bytes(4));
        $id = $this->app()->scanJobs()->start('192.0.2.52', 'deep');
        $this->app()->scanJobs()->cancel('192.0.2.52', $id);
        try {
            $this->app()->backend()->inventoryRunScanProcess(
                [PHP_BINARY, '-r', 'file_put_contents(' . var_export($marker, true) . ', "started");'],
                5,
                $id,
                'deep',
            );
            self::fail('cancelled process was started');
        } catch (InventoryCancelledException) {
            self::assertFileDoesNotExist($marker);
        } finally {
            @unlink($marker);
        }
    }

    public function testNonzeroExitIncludesCapturedDiagnostics(): void
    {
        $id = $this->app()->scanJobs()->start('192.0.2.53', 'lightweight');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('synthetic nmap failure');
        $this->app()->backend()->inventoryRunScanProcess(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "synthetic nmap failure"); exit(7);'],
            5,
            $id,
            'lightweight',
        );
    }
}
