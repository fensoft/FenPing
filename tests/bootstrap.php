<?php

declare(strict_types=1);

use FenPing\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

$sessionPath = sys_get_temp_dir() . '/fenping-phpunit-sessions';
if (!is_dir($sessionPath)) mkdir($sessionPath, 0700, true);
ini_set('session.save_path', $sessionPath);
$databasePath = sys_get_temp_dir() . '/fenping-phpunit.sqlite3';
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
$dockerNetworkCache = sys_get_temp_dir() . '/fenping-phpunit-docker-networks.json';
if (is_file($dockerNetworkCache)) {
    unlink($dockerNetworkCache);
}
putenv('DOCKER_NETWORK_CACHE=' . $dockerNetworkCache);
putenv('DATABASE_PATH=' . $databasePath);
putenv('FENPING_DATA_DIR=' . sys_get_temp_dir() . '/fenping-phpunit-data');
putenv('NETWORK');
putenv('DHCP_NETWORK=192.0.2.0/24');
putenv('EXTRA_NETWORKS=198.51.100.0/24');
putenv('INVENTORY_DOWN_RETENTION_DAYS');
putenv('IP=192.0.2.100');
putenv('DHCP_DEFAULT_ROUTER=192.0.2.1');
putenv('DISCORD_WEBHOOK_URL=');

$GLOBALS['fenping_test_application'] = Application::fromEnvironment(dirname(__DIR__));
