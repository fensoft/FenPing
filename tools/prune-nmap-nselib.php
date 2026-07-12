<?php

if (PHP_SAPI !== 'cli')
  throw new RuntimeException('this tool must run from the command line');

$nmapRoot = rtrim($argv[1] ?? '/usr/share/nmap', '/');
$scriptsRoot = $nmapRoot . '/scripts';
$libraryRoot = $nmapRoot . '/nselib';
if (!is_dir($scriptsRoot) || !is_dir($libraryRoot))
  throw new RuntimeException('Nmap scripts or nselib directory is missing');

$scripts = glob($scriptsRoot . '/*.nse') ?: array();
sort($scripts, SORT_STRING);
if (count($scripts) < 100)
  throw new RuntimeException('refusing to prune an unexpectedly small default script set');

$allFiles = recursiveFiles($libraryRoot);
$moduleFiles = array();
foreach ($allFiles as $path) {
  if (str_ends_with($path, '.lua')) {
    $relative = relativePath($libraryRoot, $path);
    $module = str_replace('/', '.', substr($relative, 0, -4));
    $moduleFiles[$module] = $path;
  }
}

$queue = new SplQueue();
foreach ($scripts as $path)
  $queue->enqueue($path);
$scanned = array();
$keptModules = array();
$sourceStrings = array();

while (!$queue->isEmpty()) {
  $path = $queue->dequeue();
  if (isset($scanned[$path]))
    continue;
  $scanned[$path] = true;
  $contents = file_get_contents($path);
  if ($contents === false)
    throw new RuntimeException('failed to read NSE source: ' . $path);
  foreach (literalStrings($contents) as $literal)
    $sourceStrings[] = $literal;

  foreach (literalModules($contents) as $module) {
    if (!isset($moduleFiles[$module]))
      continue;
    $modulePath = $moduleFiles[$module];
    if (isset($keptModules[$modulePath]))
      continue;
    $keptModules[$modulePath] = true;
    $queue->enqueue($modulePath);
  }
}

if (count($keptModules) < 50)
  throw new RuntimeException('refusing to keep an unexpectedly small nselib closure');

$removedFiles = 0;
$removedBytes = 0;
$keptData = 0;
foreach ($allFiles as $path) {
  $relative = relativePath($libraryRoot, $path);
  $keep = isset($keptModules[$path]);
  if (!$keep && str_starts_with($relative, 'data/')) {
    $dataRelative = substr($relative, strlen('data/'));
    $keep = sourceReferences($sourceStrings, array($relative, $dataRelative, basename($path)));
    if ($keep)
      $keptData++;
  }
  if ($keep)
    continue;

  $size = filesize($path);
  if ($size !== false)
    $removedBytes += $size;
  if (!unlink($path))
    throw new RuntimeException('failed to remove unused nselib file: ' . $path);
  $removedFiles++;
}

removeEmptyDirectories($libraryRoot);
printf(
  "NSE closure: %d default scripts, %d/%d Lua modules, %d referenced data files; removed %d files (%d bytes)\n",
  count($scripts), count($keptModules), count($moduleFiles), $keptData, $removedFiles, $removedBytes
);

function recursiveFiles(string $root): array {
  $files = array();
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($iterator as $entry) {
    if ($entry->isFile())
      $files[] = $entry->getPathname();
  }
  sort($files, SORT_STRING);
  return $files;
}

function relativePath(string $root, string $path): string {
  return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
}

function literalModules(string $source): array {
  $modules = array();
  $patterns = array(
    '/\\brequire\\s*(?:(?:,|\\()\\s*)?(["\x27])([A-Za-z0-9_.\\/-]+)\\1/',
    '/\\bsilent_require\\s*(?:\\(\\s*)?(["\x27])([A-Za-z0-9_.\\/-]+)\\1/'
  );
  foreach ($patterns as $pattern) {
    if (preg_match_all($pattern, $source, $matches)) {
      foreach ($matches[2] as $module)
        $modules[$module] = true;
    }
  }
  return array_keys($modules);
}

function literalStrings(string $source): array {
  if (!preg_match_all('/(["\x27])(?:\\\\.|(?!\\1).)*\\1/s', $source, $matches))
    return array();
  return $matches[0];
}

function sourceReferences(array $sources, array $names): bool {
  foreach ($names as $name) {
    if ($name === '')
      continue;
    foreach ($sources as $source) {
      if (str_contains($source, $name))
        return true;
    }
  }
  return false;
}

function removeEmptyDirectories(string $root): void {
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iterator as $entry) {
    if ($entry->isDir())
      @rmdir($entry->getPathname());
  }
}
