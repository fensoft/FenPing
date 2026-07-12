<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

trait InventoryScannerBehavior
{
public function inventoryDiscover(string $range): array {
  $output = $this->inventoryExec(
    array('nmap', '-n', '-sn', '-T3', '-oG', '-', $range),
    false,
    self::INVENTORY_DISCOVERY_TIMEOUT_SECONDS
  );
  $hosts = array();

  foreach ($output as $line) {
    if (preg_match('/^Host:\s+(\d{1,3}(?:\.\d{1,3}){3})\s+.*Status:\s+Up\b/', $line, $matches))
      $hosts[] = $matches[1];
  }

  return array_values(array_unique($hosts));
}

public function inventoryScan(string $ip, string $mode = 'deep', ?int $scanId = null): array {
  if (!$this->scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');
  if ($scanId === null)
    $scanId = $this->scanMetadataStart($ip, $mode);
  $tmp = tempnam(sys_get_temp_dir(), 'fenping-nmap-');
  if ($tmp === false) {
    $this->scanMetadataFailed($scanId, 'failed to create temporary nmap file');
    throw new RuntimeException('failed to create temporary nmap file');
  }

  try {
    $command = $this->inventoryScanCommand($ip, $mode, $tmp);

    $this->inventoryExec($command, true, $this->scanProfileTimeout($mode));
    $xml = file_get_contents($tmp);
    if ($xml === false)
      throw new RuntimeException("failed to read nmap result for $ip");

    $xml = $this->scanNormalizeXml($xml);
    $scan = $this->scanParseXml($xml, array('ip' => $ip));
    $status = $scan['status'] ?: 'unknown';
    $saved = $status === 'up';
    $changed = $this->scanMetadataComplete($scanId, $scan);
    if ($saved && function_exists('sendDiscordPortChangesForScan'))
      $this->sendDiscordPortChangesForScan($scanId);
    $this->scanPruneHistory($ip);
    return array(
      'saved' => $saved,
      'changed' => $changed,
      'status' => $status
    );
  } catch (InventoryTimeoutException $e) {
    $this->scanMetadataTimedOut($scanId, $e->getMessage());
    $this->scanPruneHistory($ip);
    throw $e;
  } catch (Throwable $e) {
    $this->scanMetadataFailed($scanId, $e->getMessage());
    $this->scanPruneHistory($ip);
    throw $e;
  } finally {
    @unlink($tmp);
  }
}

public function inventoryScanCommand(string $ip, string $profile, string $output): array {
  if ($profile === 'quick' || $profile === 'lightweight')
    return array('nmap', $ip, '-T4', '-F', '-sS', '-v', '-oX', $output);
  if ($profile === 'standard')
    return array('nmap', $ip, '-T3', '-A', '--top-ports', '1000', '-sS', '-v', '-oX', $output);
  if ($profile === 'deep')
    return array('nmap', $ip, '-T3', '-A', '-p-', '-sS', '-v', '-oX', $output);
  throw new InvalidArgumentException('invalid scan profile');
}

public function inventoryExec(array $command, bool $quiet = false, int $timeoutSeconds = self::INVENTORY_SCAN_TIMEOUT_SECONDS): array {
  $timeoutSeconds = max(1, $timeoutSeconds);
  $timedCommand = array_merge(array(
    'timeout',
    '-s',
    'TERM',
    '-k',
    '10s',
    $timeoutSeconds . 's'
  ), $command);
  $line = implode(' ', array_map('escapeshellarg', $timedCommand));
  if ($quiet)
    $line .= ' >/dev/null 2>/dev/null';
  else
    $line .= ' 2>&1';

  $output = array();
  $code = 0;
  exec($line, $output, $code);
  if (in_array($code, array(124, 137, 143), true)) {
    $duration = $this->inventoryTimeoutLabel($timeoutSeconds);
    $name = basename($command[0] ?? 'command');
    throw new InventoryTimeoutException("$name timed out after $duration");
  }
  if ($code !== 0)
    throw new RuntimeException(trim(implode(PHP_EOL, $output)) ?: "command failed: " . implode(' ', $command));

  return $output;
}

public function inventoryTimeoutLabel(int $seconds): string {
  if ($seconds % 3600 === 0) {
    $hours = (int)($seconds / 3600);
    return $hours . ' hour' . ($hours === 1 ? '' : 's');
  }
  if ($seconds % 60 === 0) {
    $minutes = (int)($seconds / 60);
    return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
  }
  return $seconds . ' second' . ($seconds === 1 ? '' : 's');
}

public function inventoryUsage(): string {
  return "Usage: php cli.php inventory [--profile lightweight|standard|deep] [1-254|IPv4]\n"
    . "       php cli.php inventory --quick [1-254|IPv4] (legacy lightweight alias)\n"
    . "       php cli.php inventory --work";
}
}
