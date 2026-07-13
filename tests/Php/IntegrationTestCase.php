<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Application;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function app(): Application
    {
        return $GLOBALS['fenping_test_application'];
    }

    protected function resetDatabase(): void
    {
        $database = $this->app()->database();
        $database->initialize();
        $pdo = $database->connection();
        $database->beginImmediate();
        try {
            $pdo->exec('PRAGMA defer_foreign_keys=ON');
            foreach ([
                'operation_failures', 'operation_status',
                'scan_port_changes', 'scans', 'scan_snapshot_script_nodes', 'scan_snapshot_scripts',
                'scan_snapshot_port_cpes', 'scan_snapshot_ports', 'scan_snapshot_extra_reasons', 'scan_snapshot_extra_ports',
                'scan_snapshot_os_cpes', 'scan_snapshot_os_classes', 'scan_snapshot_os_matches',
                'scan_snapshot_trace_hops', 'scan_snapshot_hostnames', 'scan_snapshot_addresses',
                'scan_snapshot_scopes', 'scan_snapshots', 'device_approvals', 'leases',
                'ip_conflict_devices', 'ip_conflicts', 'ip_conflict_monitor',
                'stats', 'ping', 'ips', 'range', 'netboot_images',
            ] as $table) {
                $pdo->exec('DELETE FROM ' . $table);
            }
            $database->commit();
        } catch (\Throwable $error) {
            $database->rollback();
            throw $error;
        }
    }
}
