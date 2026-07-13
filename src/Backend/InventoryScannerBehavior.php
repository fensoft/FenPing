<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use FenPing\Scan\ProgressParser;

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

    $this->inventoryRunScanProcess($command, $this->scanProfileTimeout($mode), $scanId, $mode);
    $xml = file_get_contents($tmp);
    if ($xml === false)
      throw new RuntimeException("failed to read nmap result for $ip");

    $xml = $this->scanNormalizeXml($xml);
    $scan = $this->scanParseXml($xml, array('ip' => $ip));
    $status = $scan['status'] ?: 'unknown';
    $saved = $status === 'up';
    $changed = $this->scanMetadataComplete($scanId, $scan);
    if ($saved)
      $this->sendNotificationPortChangesForScan($scanId);
    $this->scanPruneHistory($ip);
    return array(
      'saved' => $saved,
      'changed' => $changed,
      'status' => $status
    );
  } catch (InventoryCancelledException $e) {
    $this->scanMetadataCancelled($scanId);
    $this->scanPruneHistory($ip);
    throw $e;
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
    return array('nmap', $ip, '-T4', '-F', '-sS', '-v', '--stats-every', '5s', '-oX', $output);
  if ($profile === 'standard')
    return array('nmap', $ip, '-T3', '-A', '--top-ports', '1000', '-sS', '-v', '--stats-every', '5s', '-oX', $output);
  if ($profile === 'deep')
    return array('nmap', $ip, '-T3', '-A', '-p-', '-sS', '-v', '--stats-every', '5s', '-oX', $output);
  throw new InvalidArgumentException('invalid scan profile');
}

public function inventoryRunScanProcess(array $command, int $timeoutSeconds, int $scanId, string $profile): void {
  if ($this->scanMetadataCancellationRequested($scanId))
    throw new InventoryCancelledException('scan cancelled by operator');

  $descriptors = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w')
  );
  $process = proc_open($command, $descriptors, $pipes);
  if (!is_resource($process))
    throw new RuntimeException('failed to start nmap');

  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  $started = microtime(true);
  $nextCancellationCheck = $started;
  $terminationReason = null;
  $termSentAt = null;
  $buffers = array(1 => '', 2 => '');
  $diagnostic = '';
  $progress = 1;
  $exitCode = -1;

  try {
    while (true) {
      foreach (array(1, 2) as $descriptor) {
        $chunk = stream_get_contents($pipes[$descriptor]);
        if ($chunk === false || $chunk === '')
          continue;
        $diagnostic = substr($diagnostic . $chunk, -65536);
        $buffers[$descriptor] .= $chunk;
        while (($newline = strpos($buffers[$descriptor], "\n")) !== false) {
          $line = rtrim(substr($buffers[$descriptor], 0, $newline), "\r");
          $buffers[$descriptor] = substr($buffers[$descriptor], $newline + 1);
          $parsed = ProgressParser::parse($line);
          if ($parsed === null)
            continue;
          $progress = ProgressParser::overall($profile, $parsed['phase'], $parsed['phase_percent'], $progress);
          $this->scanMetadataUpdateProgress($scanId, $parsed['phase'], $progress);
        }
      }

      $status = proc_get_status($process);
      $now = microtime(true);
      if ($terminationReason === null && $now >= $nextCancellationCheck) {
        $nextCancellationCheck = $now + 1.0;
        if ($this->scanMetadataCancellationRequested($scanId))
          $terminationReason = 'cancelled';
      }
      if ($terminationReason === null && $now - $started >= max(1, $timeoutSeconds))
        $terminationReason = 'timeout';

      if ($terminationReason !== null && $status['running'] && $termSentAt === null) {
        proc_terminate($process, 15);
        $termSentAt = $now;
      } elseif ($terminationReason !== null && $status['running'] && $termSentAt !== null && $now - $termSentAt >= 10.0) {
        proc_terminate($process, 9);
      }

      if (!$status['running']) {
        $exitCode = (int)$status['exitcode'];
        break;
      }
      usleep(100000);
    }

    $finalStatus = proc_get_status($process);
    if ($finalStatus['running'])
      proc_terminate($process, 9);
  } finally {
    foreach (array(1, 2) as $descriptor) {
      $chunk = stream_get_contents($pipes[$descriptor]);
      if ($chunk !== false && $chunk !== '')
        $diagnostic = substr($diagnostic . $chunk, -65536);
      fclose($pipes[$descriptor]);
    }
    $closedCode = proc_close($process);
    if ($exitCode < 0)
      $exitCode = $closedCode;
  }

  if ($terminationReason === 'cancelled' || $this->scanMetadataCancellationRequested($scanId))
    throw new InventoryCancelledException('scan cancelled by operator');
  if ($terminationReason === 'timeout') {
    $duration = $this->inventoryTimeoutLabel($timeoutSeconds);
    throw new InventoryTimeoutException("nmap timed out after $duration");
  }
  if ($exitCode !== 0)
    throw new RuntimeException(trim($diagnostic) ?: 'nmap exited with status ' . $exitCode);

  $this->scanMetadataUpdateProgress($scanId, 'finalizing', 99);
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
  return "Usage: php cli.php inventory [--network IPv4/24] [--profile lightweight|standard|deep] [1-254|IPv4]\n"
    . "       php cli.php inventory --quick [1-254|IPv4] (legacy lightweight alias)\n"
    . "       php cli.php inventory --work";
}
}
