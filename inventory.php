<?php

const INVENTORY_SCAN_TIMEOUT_SECONDS = 7200;
const INVENTORY_DISCOVERY_TIMEOUT_SECONDS = 300;
const INVENTORY_SCAN_CONCURRENCY = 4;

class InventoryTimeoutException extends RuntimeException {}

function runInventoryCommand(array $args): int {
  try {
    if (($args[0] ?? '') === '--work')
      return runInventoryWorkerCommand(array_slice($args, 1));
    if (($args[0] ?? '') === '--run-job')
      return runInventoryJobCommand(array_slice($args, 1));

    $options = inventoryOptions($args);
    $targets = inventoryTargets($options['args']);
    $automatic = count($options['args']) === 0 && !$options['profile_explicit'];

    if (count($targets) === 0) {
      echo "discovered 0 hosts" . PHP_EOL;
      echo "queued 0 scans" . PHP_EOL;
      return 0;
    }

    if ($automatic) {
      echo "discovered " . count($targets) . " hosts" . PHP_EOL;
      $queueTargets = inventoryScheduledTargets($targets);
      echo "due " . count($queueTargets) . " scans" . PHP_EOL;
    } else {
      $queueTargets = array_map(fn($ip) => array('ip' => $ip, 'profile' => $options['profile']), $targets);
      echo "queueing " . $targets[0] . " " . $options['profile'] . PHP_EOL;
    }

    $queued = 0;
    $active = 0;
    foreach ($queueTargets as $target) {
      $result = scanMetadataEnqueue($target['ip'], $target['profile']);
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

  scanMetadataExpireStaleRunning(INVENTORY_SCAN_TIMEOUT_SECONDS + 60);
  return inventoryWorkerLoop();
}

function inventoryWorkerLoop(): int {
  $processes = array();

  while (true) {
    inventoryReapJobProcesses($processes);

    if (scanMetadataRunningCount() < INVENTORY_SCAN_CONCURRENCY) {
      foreach (scanMetadataClaimQueued(INVENTORY_SCAN_CONCURRENCY) as $job) {
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
  $profileExplicit = false;
  $remaining = array();

  for ($index = 0; $index < count($args); $index++) {
    $arg = $args[$index];
    if ($arg === '--quick' || $arg === '-q') {
      $profile = 'lightweight';
      $profileExplicit = true;
      continue;
    }
    if ($arg === 'quick') {
      $profile = 'lightweight';
      $profileExplicit = true;
      continue;
    }
    if ($arg === '--profile') {
      $profile = (string)($args[++$index] ?? '');
      if (!scanProfileIsValid($profile, false))
        throw new InvalidArgumentException(inventoryUsage());
      $profileExplicit = true;
      continue;
    }
    if (str_starts_with($arg, '--profile=')) {
      $profile = substr($arg, strlen('--profile='));
      if (!scanProfileIsValid($profile, false))
        throw new InvalidArgumentException(inventoryUsage());
      $profileExplicit = true;
      continue;
    }
    $remaining[] = $arg;
  }

  return array(
    'profile' => $profile,
    'profile_explicit' => $profileExplicit,
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

function inventoryScheduledTargets(array $hosts, ?int $now = null): array {
  $now ??= time();
  $settings = array();
  $stmt = db()->query("
    SELECT ip, scan_profile, scan_interval_hours
    FROM ips
    WHERE ip IS NOT NULL AND ip<>''
  ");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $settings[(string)$row['ip']] = $row;

  $lastScans = array();
  $stmt = db()->query("
    SELECT ip, mode, MAX(unixepoch(COALESCE(date_end, date_begin))) AS last_scan
    FROM scans
    WHERE state='complete'
    GROUP BY ip, mode
  ");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $lastScans[(string)$row['ip']][(string)$row['mode']] = (int)$row['last_scan'];

  $scheduled = array();
  foreach ($hosts as $ip) {
    $setting = $settings[$ip] ?? null;
    $profile = $setting === null
      ? SCAN_UNMANAGED_DEFAULT_PROFILE
      : (string)($setting['scan_profile'] ?? SCAN_MANAGED_DEFAULT_PROFILE);
    if (!scanProfileIsValid($profile, false))
      $profile = SCAN_MANAGED_DEFAULT_PROFILE;
    $hours = $setting === null
      ? SCAN_UNMANAGED_DEFAULT_INTERVAL_HOURS
      : (int)($setting['scan_interval_hours'] ?? SCAN_MANAGED_DEFAULT_INTERVAL_HOURS);
    if ($hours <= 0)
      continue;

    $last = $lastScans[$ip][$profile] ?? null;
    if ($profile === 'lightweight') {
      $legacy = $lastScans[$ip]['quick'] ?? null;
      if ($legacy !== null && ($last === null || $legacy > $last))
        $last = $legacy;
    }
    if ($setting === null && $last === null && !inventoryInitialUnmanagedScanDue($ip, $now))
      continue;
    if ($last !== null && $last > $now - $hours * 3600)
      continue;

    $scheduled[] = array('ip' => $ip, 'profile' => $profile);
  }
  return $scheduled;
}

function inventoryInitialUnmanagedScanDue(string $ip, int $now): bool {
  return (int)gmdate('G', $now) === inventoryInitialUnmanagedScanHour($ip);
}

function inventoryInitialUnmanagedScanHour(string $ip): int {
  $digest = hash('sha256', $ip, true);
  return ord($digest[0]) % 24;
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
    $saved = $status === 'up';
    $changed = scanMetadataComplete($scanId, $scan);
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
