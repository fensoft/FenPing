<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

trait HealthBehavior
{
public function getHealth(): array {
  $db = $this->healthDb();
  $lastPing = $db['ok'] ? $this->healthLastPingScan() : null;
  $lastInventory = $db['ok'] ? $this->healthLastInventoryScan() : null;
  $dnsmasq = $this->healthProcess('dnsmasq', '/var/run/dnsmasq.pid');
  $cron = $this->healthProcess('crond');

  return array(
    'status' => ($db['ok'] && $dnsmasq['running'] && $cron['running']) ? 'ok' : 'degraded',
    'checked_at' => $this->healthNow(),
    'web' => array(
      'ok' => true,
      'server' => $_SERVER['SERVER_SOFTWARE'] ?? '',
      'php' => PHP_VERSION,
      'sapi' => PHP_SAPI
    ),
    'db' => $db,
    'dnsmasq' => $dnsmasq,
    'cron' => $cron,
    'last_ping_scan_time' => $lastPing['time'] ?? null,
    'last_ping_scan_age_seconds' => $lastPing['age_seconds'] ?? null,
    'last_inventory_scan_time' => $lastInventory['time'] ?? null,
    'last_inventory_scan_age_seconds' => $lastInventory['age_seconds'] ?? null,
    'last_inventory_scan' => $lastInventory['scan'] ?? null
  );
}

public function healthDb(): array {
  try {
    $time = $this->db()->query("SELECT CURRENT_TIMESTAMP")->fetchColumn();
    return array(
      'ok' => true,
      'engine' => 'sqlite',
      'time' => $time === false ? null : $time
    );
  } catch (Throwable $e) {
    return array(
      'ok' => false,
      'error' => 'connection failed'
    );
  }
}

public function healthLastPingScan(): ?array {
  try {
    $time = $this->healthFetchValue("SELECT MAX(date) FROM ping");
    return $this->healthTimeResult($time);
  } catch (Throwable $e) {
    return null;
  }
}

public function healthLastInventoryScan(): ?array {
  try {
    $stmt = $this->db()->query("
      SELECT id, ip, mode, state, status, date_begin, date_end,
             COALESCE(date_end, date_begin) AS scan_time
      FROM scans
      ORDER BY COALESCE(date_end, date_begin) DESC, id DESC
      LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false)
      return null;

    $result = $this->healthTimeResult($row['scan_time'] ?? null);
    if ($result === null)
      return null;

    $result['scan'] = array(
      'id' => (int)$row['id'],
      'ip' => $row['ip'],
      'mode' => $row['mode'],
      'state' => $row['state'],
      'status' => $row['status'],
      'date_begin' => $row['date_begin'],
      'date_end' => $row['date_end']
    );
    return $result;
  } catch (Throwable $e) {
    return null;
  }
}

public function healthFetchValue(string $sql) {
  $value = $this->db()->query($sql)->fetchColumn();
  return $value === false ? null : $value;
}

public function healthTimeResult($time): ?array {
  if ($time === null || $time === '')
    return null;

  $timestamp = strtotime((string)$time);
  return array(
    'time' => (string)$time,
    'age_seconds' => $timestamp === false ? null : max(0, time() - $timestamp)
  );
}

public function healthProcess(string $name, ?string $pidFile = null): array {
  $pid = $this->healthPidFromFile($pidFile);
  if ($pid !== null && $this->healthPidRunning($pid) && $this->healthPidMatches($pid, $name)) {
    return array(
      'running' => true,
      'pid' => $pid
    );
  }

  $pids = $this->healthPidsByName($name);
  return array(
    'running' => count($pids) > 0,
    'pid' => $pids[0] ?? $pid
  );
}

public function healthPidFromFile(?string $pidFile): ?int {
  if ($pidFile === null || !is_readable($pidFile))
    return null;

  $pid = (int)trim((string)file_get_contents($pidFile));
  return $pid > 0 ? $pid : null;
}

public function healthPidRunning(int $pid): bool {
  return is_dir('/proc/' . $pid);
}

public function healthPidMatches(int $pid, string $name): bool {
  $path = '/proc/' . $pid;
  $comm = $this->healthReadProcFile($path . '/comm');
  $cmdline = str_replace("\0", ' ', $this->healthReadProcFile($path . '/cmdline'));
  $command = basename(strtok($cmdline, ' ') ?: '');
  return $comm === $name || $command === $name;
}

public function healthPidsByName(string $name): array {
  $pids = array();
  foreach (glob('/proc/[0-9]*') ?: array() as $path) {
    $pid = (int)basename($path);
    if ($pid <= 0)
      continue;

    if ($this->healthPidMatches($pid, $name))
      $pids[] = $pid;
  }

  sort($pids);
  return $pids;
}

public function healthReadProcFile(string $path): string {
  if (!is_readable($path))
    return '';

  $contents = file_get_contents($path);
  return $contents === false ? '' : trim($contents);
}

public function healthNow(): string {
  return date('Y-m-d H:i:s');
}
}
