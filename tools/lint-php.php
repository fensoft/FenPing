<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    $root . '/api.php',
    $root . '/cli.php',
    $root . '/public/api.php',
    $root . '/tests/bootstrap.php',
];

foreach ([$root . '/src', $root . '/tests/Php'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
foreach ($files as $file) {
    $command = [PHP_BINARY, '-l', $file];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "failed to start PHP lint for $file" . PHP_EOL);
        exit(1);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        fwrite(STDERR, $stdout . $stderr);
        exit($code);
    }
}

echo 'PHP lint passed: ' . count($files) . ' files' . PHP_EOL;
