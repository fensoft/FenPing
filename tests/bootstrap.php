<?php

declare(strict_types=1);

use FenPing\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

$databasePath = sys_get_temp_dir() . '/fenping-phpunit.sqlite3';
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
putenv('DATABASE_PATH=' . $databasePath);
putenv('FENPING_DATA_DIR=' . sys_get_temp_dir() . '/fenping-phpunit-data');
putenv('NETWORK=192.0.2');
putenv('IP=192.0.2.100');
putenv('DISCORD_WEBHOOK_URL=');

$GLOBALS['fenping_test_application'] = Application::fromEnvironment(dirname(__DIR__));
