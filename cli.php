<?php

declare(strict_types=1);

use FenPing\Application;

require __DIR__ . '/vendor/autoload.php';

exit(Application::fromEnvironment(__DIR__)->cli()->run($argv));
