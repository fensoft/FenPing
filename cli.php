<?php

declare(strict_types=1);

use FenPing\Application;
require __DIR__ . '/vendor/autoload.php';

try {
    $application = Application::fromEnvironment(__DIR__);
} catch (\Throwable $error) {
    fwrite(STDERR, 'configuration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

exit($application->cli()->run($argv));
