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

trait BackupArchiveValidationBehavior
{
public function backupWriteJson(string $path, array $data): void {
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
  if (file_put_contents($path, $json . PHP_EOL) === false)
    throw new RuntimeException("failed to write $path");
}

public function backupJsonEncode($value): string {
  return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

public function backupWriteChunk($stream, string $value): void {
  $length = strlen($value);
  $written = 0;
  while ($written < $length) {
    $bytes = fwrite($stream, substr($value, $written));
    if ($bytes === false || $bytes === 0)
      throw new RuntimeException('failed to write db.json');
    $written += $bytes;
  }
}

public function backupReadJson(string $path, string $label): array {
  if (!is_file($path) || !is_readable($path))
    throw new RuntimeException("archive does not contain $label");
  $this->backupEnsureJsonMemory($path);
  $json = file_get_contents($path);
  if ($json === false)
    throw new RuntimeException("failed to read $label");
  try {
    $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    throw new RuntimeException("invalid $label: " . $e->getMessage());
  }
  if (!is_array($document))
    throw new RuntimeException("invalid $label: expected an object");
  return $document;
}

public function backupEnsureJsonMemory(string $path): void {
  $bytes = filesize($path);
  if ($bytes === false)
    return;
  $needed = max(128 * 1024 * 1024, $bytes * 8 + 16 * 1024 * 1024);
  $configured = trim((string)ini_get('memory_limit'));
  if ($configured === '' || $configured === '-1')
    return;
  $unit = strtolower(substr($configured, -1));
  $current = (int)$configured;
  if ($unit === 'g')
    $current *= 1024 * 1024 * 1024;
  elseif ($unit === 'm')
    $current *= 1024 * 1024;
  elseif ($unit === 'k')
    $current *= 1024;
  if ($current < $needed)
    ini_set('memory_limit', (string)$needed);
}

public function backupValidateDocument(array $document, string $format, string $label): void {
  if (($document['format'] ?? null) !== $format)
    throw new RuntimeException("unsupported $label format");
  $version = $document['version'] ?? null;
  if (!is_string($version) || !preg_match('/^([0-9]+)\.([0-9]+)$/', $version, $match))
    throw new RuntimeException("invalid backup version in $label");
  if ($match[1] !== '1' || version_compare($version, self::BACKUP_VERSION, '<'))
    throw new RuntimeException("unsupported backup version $version in $label");
}

public function backupQuoteIdentifier(string $identifier): string {
  if (!preg_match('/^[A-Za-z0-9_-]+$/', $identifier))
    throw new RuntimeException("invalid database identifier: $identifier");
  return '`' . $identifier . '`';
}

public function backupValidateArchive(string $source): void {
  $output = $this->backupRunProcess(array('tar', '-tzf', $source));
  foreach (explode("\n", trim($output)) as $entry) {
    if ($entry === '')
      continue;
    if (!$this->backupArchiveEntrySafe($entry))
      throw new RuntimeException("unsafe archive entry: $entry");
  }
}

public function backupArchiveEntrySafe(string $entry): bool {
  $entry = str_replace('\\', '/', $entry);
  if ($entry === '.' || $entry === './')
    return true;
  if ($entry === '' || $entry[0] === '/')
    return false;
  foreach (explode('/', $entry) as $part) {
    if ($part === '..')
      return false;
  }
  return true;
}

public function backupReloadHosts(): void {
  if (!function_exists('runHostsCommand'))
    return;

  $code = $this->runHostsCommand();
  if ($code !== 0)
    throw new RuntimeException('dnsmasq reload failed after restore');
}

public function backupRunProcess(array $command, array $env = array(), ?string $stdinFile = null, ?string $stdoutFile = null): string {
  $line = implode(' ', array_map('escapeshellarg', $command));
  $descriptors = array(
    0 => $stdinFile === null ? array('pipe', 'r') : array('file', $stdinFile, 'r'),
    1 => $stdoutFile === null ? array('pipe', 'w') : array('file', $stdoutFile, 'w'),
    2 => array('pipe', 'w')
  );

  $oldEnv = $this->backupApplyEnv($env);
  try {
    $process = proc_open($line, $descriptors, $pipes);
    if (!is_resource($process))
      throw new RuntimeException("failed to run: $line");

    if ($stdinFile === null)
      fclose($pipes[0]);

    $stdout = '';
    if ($stdoutFile === null) {
      $stdout = stream_get_contents($pipes[1]);
      fclose($pipes[1]);
    }

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
  } finally {
    $this->backupRestoreEnv($oldEnv);
  }

  if ($code !== 0) {
    $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    throw new RuntimeException($message !== '' ? $message : "command failed: $line");
  }

  return $stdout;
}

public function backupApplyEnv(array $env): array {
  $old = array();
  foreach ($env as $name => $value) {
    $old[$name] = getenv($name);
    putenv($name . '=' . $value);
  }
  return $old;
}

public function backupRestoreEnv(array $old): void {
  foreach ($old as $name => $value) {
    if ($value === false)
      putenv($name);
    else
      putenv($name . '=' . $value);
  }
}
}
