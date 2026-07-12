<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

trait BackupFilesystemBehavior
{
public function backupCopyDirectory(string $source, string $target): void {
  $this->backupEnsureDir($target);
  if (!is_dir($source))
    return;

  $items = scandir($source);
  if ($items === false)
    throw new RuntimeException("failed to read $source");

  foreach ($items as $item) {
    if ($item === '.' || $item === '..')
      continue;

    $from = $source . '/' . $item;
    $to = $target . '/' . $item;
    if (is_link($from))
      continue;
    if (is_dir($from)) {
      $this->backupCopyDirectory($from, $to);
      continue;
    }
    if (is_file($from)) {
      if (!copy($from, $to))
        throw new RuntimeException("failed to copy $from");
      chmod($to, 0644);
    }
  }
}

public function backupReplaceDirectory(string $source, string $target): void {
  $this->backupEnsureDir($target);
  $this->backupClearDirectory($target);
  $this->backupCopyDirectory($source, $target);
}

public function backupClearDirectory(string $target): void {
  $real = realpath($target);
  $allowed = array(realpath($this->backupNetbootDir()));
  if ($real === false || !in_array($real, $allowed, true))
    throw new RuntimeException("refusing to clear unexpected directory: $target");

  $items = scandir($target);
  if ($items === false)
    throw new RuntimeException("failed to read $target");

  foreach ($items as $item) {
    if ($item === '.' || $item === '..')
      continue;
    $this->backupRemoveTree($target . '/' . $item);
  }
}

public function backupRemoveTree(string $path): void {
  if (is_link($path) || is_file($path)) {
    @unlink($path);
    return;
  }

  if (!is_dir($path))
    return;

  $items = scandir($path);
  if ($items !== false) {
    foreach ($items as $item) {
      if ($item === '.' || $item === '..')
        continue;
      $this->backupRemoveTree($path . '/' . $item);
    }
  }
  @rmdir($path);
}

public function backupTempDir(string $prefix): string {
  $base = sys_get_temp_dir();
  $dir = tempnam($base, $prefix);
  if ($dir === false)
    throw new RuntimeException('failed to create temporary directory');
  @unlink($dir);
  if (!mkdir($dir, 0700, true))
    throw new RuntimeException('failed to create temporary directory');
  return $dir;
}

public function backupEnsureDir(string $dir): void {
  if (!is_dir($dir) && !mkdir($dir, 0755, true))
    throw new RuntimeException("failed to create $dir");
}

public function backupAbsolutePath(string $path): string {
  if ($path === '')
    return $path;
  if ($path[0] === '/')
    return $path;
  return getcwd() . '/' . $path;
}

public function backupNetbootDir(): string {
  return $this->config->dataDir . '/netboot';
}

public function backupIsArchive(string $path): bool {
  return str_ends_with($path, '.tgz') || str_ends_with($path, '.tar.gz');
}

public function backupCountFiles(string $dir): int {
  if (!is_dir($dir))
    return 0;

  $count = 0;
  $items = scandir($dir);
  if ($items === false)
    return 0;

  foreach ($items as $item) {
    if ($item === '.' || $item === '..')
      continue;
    $path = $dir . '/' . $item;
    if (is_dir($path) && !is_link($path))
      $count += $this->backupCountFiles($path);
    elseif (is_file($path))
      $count++;
  }
  return $count;
}

public function backupFormatBytes($value): string {
  $bytes = (float)$value;
  $units = array('B', 'KB', 'MB', 'GB');
  $unit = 0;
  while ($bytes >= 1024 && $unit < count($units) - 1) {
    $bytes /= 1024;
    $unit++;
  }
  return ($unit === 0 ? (string)(int)$bytes : number_format($bytes, 1)) . ' ' . $units[$unit];
}
}
