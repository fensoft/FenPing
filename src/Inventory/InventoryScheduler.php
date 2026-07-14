<?php

declare(strict_types=1);

namespace FenPing\Inventory;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Docker\DockerNetworkCache;
use FenPing\Health\OperationTracker;
use FenPing\Host\HostMetadataRepository;
use FenPing\Network\NetworkManager;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Scan\ProfileCatalog;
use FenPing\Scan\ScanJobRepository;

final readonly class InventoryScheduler
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private DockerNetworkCache $dockerNetworks,
        private HostMetadataRepository $metadata,
        private InventoryScanner $scanner,
        private ScanJobRepository $jobs,
        private ProfileCatalog $profiles,
        private NetworkManager $networks,
        private OperationTracker $operations,
        private LiveUpdatePublisher $liveUpdates,
    ) {
    }
public const INVENTORY_SCAN_TIMEOUT_SECONDS = 7200;
public const INVENTORY_DISCOVERY_TIMEOUT_SECONDS = 300;

public function runInventoryCommand(array $args): int {
  $automatic = false;
  try {
    if (($args[0] ?? '') === '--work')
      return $this->runInventoryWorkerCommand(array_slice($args, 1));
    if (($args[0] ?? '') === '--run-job')
      return $this->runInventoryJobCommand(array_slice($args, 1));

    $options = $this->inventoryOptions($args);
    $automatic = count($options['args']) === 0 && !$options['profile_explicit'] && $options['network'] === null;
    if ($automatic)
      $this->operations->started('discovery');
    if ($options['network'] !== null) {
      $selectedNetwork = $this->networks->forCidr($options['network']);
    } elseif ($automatic) {
      $selectedNetwork = $this->networks->nextScheduled('inventory');
    } elseif (($options['args'][0] ?? '') !== '' && !ctype_digit((string)$options['args'][0])) {
      $selectedNetwork = $this->networks->forIp((string)$options['args'][0]);
    } else {
      $selectedNetwork = $this->config->dhcpNetwork;
    }
    $targets = $this->inventoryTargets($options['args'], $selectedNetwork);

    if (count($targets) === 0) {
      echo "discovered 0 hosts" . PHP_EOL;
      echo "queued 0 scans" . PHP_EOL;
      if ($automatic)
        $this->operations->succeeded('discovery');
      return 0;
    }

    if ($automatic) {
      echo "discovered " . count($targets) . " hosts" . PHP_EOL;
      $queueTargets = $this->inventoryScheduledTargets($targets, null, $selectedNetwork->cidr);
      echo "due " . count($queueTargets) . " scans" . PHP_EOL;
    } else {
      $queueTargets = array_map(fn($ip) => array('ip' => $ip, 'profile' => $options['profile']), $targets);
      echo "queueing " . $targets[0] . " " . $options['profile'] . PHP_EOL;
    }

    $queued = 0;
    $active = 0;
    foreach ($queueTargets as $target) {
      $result = $this->jobs->enqueue($target['ip'], $target['profile'], $automatic ? 'scheduled' : 'manual');
      if ($result['created'])
        $queued++;
      else
        $active++;
    }
    if ($queueTargets !== array())
      $this->liveUpdates->publish(LiveUpdateScope::Scans);

    echo "queued $queued scans" . PHP_EOL;
    if ($active > 0)
      echo "$active scans already queued or running" . PHP_EOL;
    if ($automatic)
      $this->operations->succeeded('discovery');
    return 0;
  } catch (Throwable $e) {
    if ($automatic)
      $this->operations->failed('discovery', $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

public function runInventoryWorkerCommand(array $args): int {
  if (count($args) !== 0)
    throw new InvalidArgumentException($this->scanner->inventoryUsage());

  $this->jobs->expireRunning(self::INVENTORY_SCAN_TIMEOUT_SECONDS + 60);
  return $this->inventoryWorkerLoop();
}

public function inventoryWorkerLoop(): int {
  $processes = array();

  while (true) {
    $claimedThisPass = false;
    $this->inventoryReapJobProcesses($processes);

    if ($this->jobs->runningCount() < $this->config->scanGlobalConcurrency) {
      foreach ($this->jobs->claimQueued($this->config->scanGlobalConcurrency) as $job) {
        $claimedThisPass = true;
        $process = $this->inventoryStartJobProcess((int)$job['id']);
        if ($process === false) {
          $this->jobs->fail((int)$job['id'], 'failed to start scan worker');
          continue;
        }
        $processes[(int)$job['id']] = $process;
      }
    }

    if (count($processes) === 0) {
      $queued = $this->jobs->queuedCount();
      $running = $this->jobs->runningCount();
      if ($queued === 0 || !$claimedThisPass || $running >= $this->config->scanGlobalConcurrency)
        return 0;
    }

    usleep(1000000);
  }
}

public function inventoryStartJobProcess(int $scanId) {
  $command = array(PHP_BINARY, $this->config->projectDir . '/cli.php', 'inventory', '--run-job', (string)$scanId);
  $descriptors = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('file', '/dev/null', 'a'),
    2 => array('file', '/dev/null', 'a')
  );
  return proc_open($command, $descriptors, $pipes);
}

public function inventoryReapJobProcesses(array &$processes): void {
  foreach ($processes as $scanId => $process) {
    $status = proc_get_status($process);
    if ($status['running'])
      continue;

    $exitCode = (int)$status['exitcode'];
    proc_close($process);
    unset($processes[$scanId]);

    $metadata = $this->jobs->findJob((int)$scanId);
    if ($metadata !== null && $metadata['state'] === 'running') {
      if ($metadata['cancel_requested']) {
        $this->jobs->markCancelled((int)$scanId);
        continue;
      }

      $message = $exitCode === 0
        ? 'scan worker exited without completing metadata'
        : "scan worker exited with code $exitCode";
      $this->jobs->fail((int)$scanId, $message);
    }
  }
}

public function runInventoryJobCommand(array $args): int {
  if (count($args) !== 1 || !ctype_digit((string)$args[0]))
    throw new InvalidArgumentException($this->scanner->inventoryUsage());

  $scanId = (int)$args[0];
  $job = $this->jobs->findJob($scanId);
  if ($job === null)
    throw new InvalidArgumentException("scan job $scanId not found");
  if ($job['state'] !== 'running')
    throw new RuntimeException("scan job $scanId is not running");

  $this->networks->forIp($job['ip']);

  $result = $this->scanner->inventoryScan($job['ip'], $job['mode'], $scanId);
  echo $job['ip'] . ($result['saved'] ? ' saved' : ' skipped') . PHP_EOL;
  return 0;
}

public function inventoryOptions(array $args): array {
  $profile = 'deep';
  $profileExplicit = false;
  $network = null;
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
      if (!$this->profiles->isValid($profile, false))
        throw new InvalidArgumentException($this->scanner->inventoryUsage());
      $profileExplicit = true;
      continue;
    }
    if (str_starts_with($arg, '--profile=')) {
      $profile = substr($arg, strlen('--profile='));
      if (!$this->profiles->isValid($profile, false))
        throw new InvalidArgumentException($this->scanner->inventoryUsage());
      $profileExplicit = true;
      continue;
    }
    if ($arg === '--network') {
      $network = (string)($args[++$index] ?? '');
      if ($network === '')
        throw new InvalidArgumentException($this->scanner->inventoryUsage());
      continue;
    }
    if (str_starts_with($arg, '--network=')) {
      $network = substr($arg, strlen('--network='));
      if ($network === '')
        throw new InvalidArgumentException($this->scanner->inventoryUsage());
      continue;
    }
    $remaining[] = $arg;
  }

  return array(
    'profile' => $profile,
    'profile_explicit' => $profileExplicit,
    'network' => $network,
    'args' => $remaining
  );
}

public function inventoryTargets(array $args, \FenPing\Network\Ipv4Network $network): array {
  if (count($args) > 1)
    throw new InvalidArgumentException($this->scanner->inventoryUsage());

  $target = $args[0] ?? '';
  if ($target !== '') {
    if (ctype_digit($target)) {
      $octet = intval($target);
      if ($octet < 1 || $octet > 254)
        throw new InvalidArgumentException($this->scanner->inventoryUsage());
      return array($network->host($octet));
    }

    if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
      throw new InvalidArgumentException($this->scanner->inventoryUsage());

    $this->networks->forIp($target);
    return array($target);
  }

  return $this->inventoryExcludeAutomaticTargets($this->scanner->inventoryDiscover($network->discoveryRange()));
}

public function inventoryExcludeAutomaticTargets(array $hosts): array {
  $excluded = array_filter(array_unique(array(
    (string)($this->config->applianceIp ?? ''),
    (string)(getenv('IP') ?: '')
  )));
  return array_values(array_diff($hosts, $excluded));
}

public function inventoryScheduledTargets(array $hosts, ?int $now = null, ?string $networkCidr = null): array {
  $now ??= time();
  $settings = array();
  $stmt = $this->database->connection()->query("
    SELECT ip, scan_profile, scan_interval_hours
    FROM ips
    WHERE ip IS NOT NULL AND ip<>''
  ");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['managed'] = true;
    $settings[(string)$row['ip']] = $row;
  }

  $deviceMetadata = $this->metadata->inventoryDeviceMetadataMap();
  $profileDepth = array('lightweight' => 1, 'standard' => 2, 'deep' => 3);
  foreach ($this->dockerNetworks->containers() as $container) {
    if ($networkCidr !== null && $container['cidr'] !== $networkCidr)
      continue;
    $ip = $container['ip'];
    if (isset($settings[$ip]['managed']))
      continue;
    $key = $this->metadata->inventoryDeviceMetadataKey($container['network'], $container['container']);
    if (!isset($deviceMetadata[$key]))
      continue;
    $metadata = $deviceMetadata[$key];
    $candidate = array(
      'ip' => $ip,
      'scan_profile' => $metadata['scan_profile'],
      'scan_interval_hours' => $metadata['scan_interval_hours'],
      'device' => true
    );
    if (!isset($settings[$ip])) {
      $settings[$ip] = $candidate;
      continue;
    }
    $current = $settings[$ip];
    if (($profileDepth[$candidate['scan_profile']] ?? 0) > ($profileDepth[$current['scan_profile']] ?? 0))
      $current['scan_profile'] = $candidate['scan_profile'];
    $currentHours = (int)$current['scan_interval_hours'];
    $candidateHours = (int)$candidate['scan_interval_hours'];
    if ($currentHours <= 0 || ($candidateHours > 0 && $candidateHours < $currentHours))
      $current['scan_interval_hours'] = $candidateHours;
    $settings[$ip] = $current;
  }

  $lastScans = array();
  $stmt = $this->database->connection()->query("
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
      ? ProfileCatalog::UNMANAGED_DEFAULT
      : (string)($setting['scan_profile'] ?? ProfileCatalog::MANAGED_DEFAULT);
    if (!$this->profiles->isValid($profile, false))
      $profile = ProfileCatalog::MANAGED_DEFAULT;
    $hours = $setting === null
      ? ProfileCatalog::UNMANAGED_INTERVAL_HOURS
      : (int)($setting['scan_interval_hours'] ?? ProfileCatalog::MANAGED_INTERVAL_HOURS);
    if ($hours <= 0)
      continue;

    $last = $lastScans[$ip][$profile] ?? null;
    if ($profile === 'lightweight') {
      $legacy = $lastScans[$ip]['quick'] ?? null;
      if ($legacy !== null && ($last === null || $legacy > $last))
        $last = $legacy;
    }
    if ($setting === null && $last === null && !$this->inventoryInitialUnmanagedScanDue($ip, $now))
      continue;
    if ($last !== null && $last > $now - $hours * 3600)
      continue;

    $scheduled[] = array('ip' => $ip, 'profile' => $profile);
  }
  return $scheduled;
}

public function inventoryInitialUnmanagedScanDue(string $ip, int $now): bool {
  $hour = (int)gmdate('G', $now);
  $slot = $this->inventoryInitialUnmanagedScanHour($ip);
  $window = min(24, max(1, count($this->networks->configured())));
  return (($hour - $slot + 24) % 24) < $window;
}

public function inventoryInitialUnmanagedScanHour(string $ip): int {
  $digest = hash('sha256', $ip, true);
  return ord($digest[0]) % 24;
}
}
