<?php

const INVENTORY_SCAN_TIMEOUT_SECONDS = 7200;
const INVENTORY_DISCOVERY_TIMEOUT_SECONDS = 300;
const INVENTORY_SCAN_CONCURRENCY = 4;
const INVENTORY_WORKER_LOCK = '/tmp/fenping-inventory-worker.lck';

class InventoryTimeoutException extends RuntimeException {}

function runInventoryCommand(array $args): int {
  try {
    if (($args[0] ?? '') === '--work')
      return runInventoryWorkerCommand(array_slice($args, 1));
    if (($args[0] ?? '') === '--run-job')
      return runInventoryJobCommand(array_slice($args, 1));

    $options = inventoryOptions($args);
    $targets = inventoryTargets($options['args']);

    if (count($targets) === 0) {
      echo "discovered 0 hosts" . PHP_EOL;
      echo "queued 0 scans" . PHP_EOL;
      return 0;
    }

    if (count($options['args']) === 0)
      echo "discovered " . count($targets) . " hosts" . PHP_EOL;
    else
      echo "queueing " . $targets[0] . " " . $options['profile'] . PHP_EOL;

    $queued = 0;
    $active = 0;
    foreach ($targets as $ip) {
      $result = scanMetadataEnqueue($ip, $options['profile']);
      if ($result['created'])
        $queued++;
      else
        $active++;
    }

    echo "queued $queued scans" . PHP_EOL;
    if ($active > 0)
      echo "$active scans already queued or running" . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

function runInventoryWorkerCommand(array $args): int {
  if (count($args) !== 0)
    throw new InvalidArgumentException(inventoryUsage());

  $lock = fopen(INVENTORY_WORKER_LOCK, 'c');
  if ($lock === false)
    throw new RuntimeException('failed to open inventory worker lock');
  if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    echo "inventory worker already running" . PHP_EOL;
    return 0;
  }

  try {
    scanMetadataExpireStaleRunning(INVENTORY_SCAN_TIMEOUT_SECONDS + 60);
    return inventoryWorkerLoop();
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function inventoryWorkerLoop(): int {
  $processes = array();

  while (true) {
    inventoryReapJobProcesses($processes);

    $slots = max(0, INVENTORY_SCAN_CONCURRENCY - scanMetadataRunningCount());
    if ($slots > 0) {
      foreach (scanMetadataClaimQueued($slots) as $job) {
        $process = inventoryStartJobProcess((int)$job['id']);
        if ($process === false) {
          scanMetadataFailed((int)$job['id'], 'failed to start scan worker');
          continue;
        }
        $processes[(int)$job['id']] = $process;
      }
    }

    if (count($processes) === 0) {
      $queued = scanMetadataQueuedCount();
      $running = scanMetadataRunningCount();
      if ($queued === 0 || $running >= INVENTORY_SCAN_CONCURRENCY)
        return 0;
    }

    usleep(1000000);
  }
}

function inventoryStartJobProcess(int $scanId) {
  $command = array(PHP_BINARY, __DIR__ . '/cli.php', 'inventory', '--run-job', (string)$scanId);
  $descriptors = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('file', '/dev/null', 'a'),
    2 => array('file', '/dev/null', 'a')
  );
  return proc_open($command, $descriptors, $pipes);
}

function inventoryReapJobProcesses(array &$processes): void {
  foreach ($processes as $scanId => $process) {
    $status = proc_get_status($process);
    if ($status['running'])
      continue;

    $exitCode = (int)$status['exitcode'];
    proc_close($process);
    unset($processes[$scanId]);

    $metadata = scanMetadataJobById((int)$scanId);
    if ($metadata !== null && $metadata['state'] === 'running') {
      $message = $exitCode === 0
        ? 'scan worker exited without completing metadata'
        : "scan worker exited with code $exitCode";
      scanMetadataFailed((int)$scanId, $message);
    }
  }
}

function runInventoryJobCommand(array $args): int {
  if (count($args) !== 1 || !ctype_digit((string)$args[0]))
    throw new InvalidArgumentException(inventoryUsage());

  $scanId = (int)$args[0];
  $job = scanMetadataJobById($scanId);
  if ($job === null)
    throw new InvalidArgumentException("scan job $scanId not found");
  if ($job['state'] !== 'running')
    throw new RuntimeException("scan job $scanId is not running");

  $result = inventoryScan($job['ip'], $job['mode'], $scanId);
  echo $job['ip'] . ($result['saved'] ? ' saved' : ' skipped') . PHP_EOL;
  return 0;
}

function inventoryOptions(array $args): array {
  $profile = 'deep';
  $remaining = array();

  for ($index = 0; $index < count($args); $index++) {
    $arg = $args[$index];
    if ($arg === '--quick' || $arg === '-q') {
      $profile = 'lightweight';
      continue;
    }
    if ($arg === 'quick') {
      $profile = 'lightweight';
      continue;
    }
    if ($arg === '--profile') {
      $profile = (string)($args[++$index] ?? '');
      if (!scanProfileIsValid($profile, false))
        throw new InvalidArgumentException(inventoryUsage());
      continue;
    }
    if (str_starts_with($arg, '--profile=')) {
      $profile = substr($arg, strlen('--profile='));
      if (!scanProfileIsValid($profile, false))
        throw new InvalidArgumentException(inventoryUsage());
      continue;
    }
    $remaining[] = $arg;
  }

  return array(
    'profile' => $profile,
    'args' => $remaining
  );
}

function inventoryTargets(array $args): array {
  global $network;

  if (count($args) > 1)
    throw new InvalidArgumentException(inventoryUsage());

  $target = $args[0] ?? '';
  if ($target !== '') {
    if (ctype_digit($target)) {
      $octet = intval($target);
      if ($octet < 1 || $octet > 254)
        throw new InvalidArgumentException(inventoryUsage());
      return array($network . '.' . $octet);
    }

    if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
      throw new InvalidArgumentException(inventoryUsage());

    return array($target);
  }

  return inventoryExcludeAutomaticTargets(inventoryDiscover($network . '.1-254'));
}

function inventoryExcludeAutomaticTargets(array $hosts): array {
  global $myself;

  $excluded = array_filter(array_unique(array(
    (string)($myself ?? ''),
    (string)(getenv('IP') ?: '')
  )));
  return array_values(array_diff($hosts, $excluded));
}

function inventoryDiscover(string $range): array {
  $output = inventoryExec(
    array('nmap', '-n', '-sn', '-T3', '-oG', '-', $range),
    false,
    INVENTORY_DISCOVERY_TIMEOUT_SECONDS
  );
  $hosts = array();

  foreach ($output as $line) {
    if (preg_match('/^Host:\s+(\d{1,3}(?:\.\d{1,3}){3})\s+.*Status:\s+Up\b/', $line, $matches))
      $hosts[] = $matches[1];
  }

  return array_values(array_unique($hosts));
}

function inventoryScan(string $ip, string $mode = 'deep', ?int $scanId = null): array {
  if (!scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');
  if ($scanId === null)
    $scanId = scanMetadataStart($ip, $mode);
  $tmp = tempnam(sys_get_temp_dir(), 'fenping-nmap-');
  if ($tmp === false) {
    scanMetadataFailed($scanId, 'failed to create temporary nmap file');
    throw new RuntimeException('failed to create temporary nmap file');
  }

  try {
    $command = inventoryScanCommand($ip, $mode, $tmp);

    inventoryExec($command, true, scanProfileTimeout($mode));
    $xml = file_get_contents($tmp);
    if ($xml === false)
      throw new RuntimeException("failed to read nmap result for $ip");

    $xml = scanNormalizeXml($xml);
    $scan = scanParseXml($xml, array('ip' => $ip));
    $status = $scan['status'] ?: 'unknown';
    $xmlHash = scanXmlHash($xml, $ip);
    $saved = $status === 'up';
    $changed = scanMetadataComplete(
      $scanId,
      $status,
      count($scan['ports']),
      $scan['duration'],
      $saved ? $xml : null,
      $saved ? $xmlHash : null
    );
    if ($saved && function_exists('sendDiscordPortChangesForScan'))
      sendDiscordPortChangesForScan($scanId);
    scanPruneHistory($ip);
    return array(
      'saved' => $saved,
      'changed' => $changed,
      'status' => $status
    );
  } catch (InventoryTimeoutException $e) {
    scanMetadataTimedOut($scanId, $e->getMessage());
    scanPruneHistory($ip);
    throw $e;
  } catch (Throwable $e) {
    scanMetadataFailed($scanId, $e->getMessage());
    scanPruneHistory($ip);
    throw $e;
  } finally {
    @unlink($tmp);
  }
}

function inventoryScanCommand(string $ip, string $profile, string $output): array {
  if ($profile === 'quick' || $profile === 'lightweight')
    return array('nmap', $ip, '-T4', '-F', '-sS', '-v', '-oX', $output);
  if ($profile === 'standard')
    return array('nmap', $ip, '-T3', '-A', '--top-ports', '1000', '-sS', '-v', '-oX', $output);
  if ($profile === 'deep')
    return array('nmap', $ip, '-T3', '-A', '-p-', '-sS', '-v', '-oX', $output);
  throw new InvalidArgumentException('invalid scan profile');
}

function inventoryExec(array $command, bool $quiet = false, int $timeoutSeconds = INVENTORY_SCAN_TIMEOUT_SECONDS): array {
  $timeoutSeconds = max(1, $timeoutSeconds);
  $timedCommand = array_merge(array(
    'timeout',
    '--signal=TERM',
    '--kill-after=10s',
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
  if ($code === 124 || $code === 137) {
    $duration = inventoryTimeoutLabel($timeoutSeconds);
    $name = basename($command[0] ?? 'command');
    throw new InventoryTimeoutException("$name timed out after $duration");
  }
  if ($code !== 0)
    throw new RuntimeException(trim(implode(PHP_EOL, $output)) ?: "command failed: " . implode(' ', $command));

  return $output;
}

function inventoryTimeoutLabel(int $seconds): string {
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

function inventoryUsage(): string {
  return "Usage: php cli.php inventory [--profile lightweight|standard|deep] [1-254|IPv4]\n"
    . "       php cli.php inventory --quick [1-254|IPv4] (legacy lightweight alias)\n"
    . "       php cli.php inventory --work";
}
