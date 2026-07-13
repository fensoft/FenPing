<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use FenPing\Realtime\LiveUpdateScope;

trait CliBehavior
{
public function runLockedCliCommand(string $path, string $label, callable $callback): int {
  $lock = fopen($path, 'c');
  if ($lock === false) {
    fwrite(STDERR, "failed to open $label lock" . PHP_EOL);
    return 1;
  }
  if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    fwrite(STDERR, "$label already running" . PHP_EOL);
    return 75;
  }

  try {
    return $callback();
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

public function runScanPortBackfillCommand() {
  $inserted = $this->scanPortChangesBackfill();
  if ($inserted > 0)
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
  echo "scan port changes backfill: $inserted inserted" . PHP_EOL;
  return 0;
}

public function runDnsmasqLeasesCommand(array $args): int {
  if (count($args) !== 0) {
    fwrite(STDERR, "Usage: php cli.php dnsmasq-leases" . PHP_EOL);
    return 2;
  }

  try {
    $this->importDnsmasqLeases();
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

public function runPingCommand($args) {
  $networkCidr = getenv('SCAN_NETWORK') ?: null;
  $remaining = array();
  for ($index = 0; $index < count($args); $index++) {
    if ($args[$index] === '--network') {
      $networkCidr = (string)($args[++$index] ?? '');
      continue;
    }
    if (str_starts_with((string)$args[$index], '--network=')) {
      $networkCidr = substr((string)$args[$index], strlen('--network='));
      continue;
    }
    $remaining[] = $args[$index];
  }
  try {
    if ($networkCidr !== null)
      $selectedNetwork = $this->networks->forCidr($networkCidr);
    elseif ($remaining === array())
      $selectedNetwork = $this->networks->nextScheduled('ping');
    else
      $selectedNetwork = $this->config->dhcpNetwork;
  } catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    return 1;
  }
  $arg = $remaining[0] ?? '';
  $debugEnv = getenv('DEBUG');
  $debug = $arg !== '' || ($debugEnv !== false && $debugEnv !== '');
  $from = 1;
  $to = 254;

  if ($arg !== '' && $arg !== 'DEBUG') {
    if (!ctype_digit($arg) || intval($arg) < 1 || intval($arg) > 254) {
      fwrite(STDERR, "Usage: php cli.php ping [1-254|DEBUG]\n");
      return 2;
    }
    $from = intval($arg);
    $to = $from;
  }

  $targets = array();
  for ($i = $from; $i <= $to; $i++)
    $targets[$i] = $selectedNetwork->host($i);

  $notifyAfterId = $this->discordNotificationsEnabled() ? $this->statsMaxId() : null;
  $conflictScan = $this->ipConflictDetector->scan($selectedNetwork);
  if (!$conflictScan['successful'])
    fwrite(STDERR, 'IP conflict scan failed: ' . $conflictScan['error'] . PHP_EOL);
  else
    $this->sendDiscordIpConflictChanges($this->ipConflictTransitionDetails($conflictScan['transitions']));

  $hosts = $this->pingHosts($targets, $this->config->interface ?? '', array_filter(array_unique(array(
    getenv('IP') ?: '',
    $this->config->applianceIp ?? ''
  ))));
  $this->sendDiscordStatusChangesSince($notifyAfterId);

  if ($debug) {
    foreach ($hosts as $host)
      echo $host["ip"] . " " . $host["status"] . PHP_EOL;
  }

  return 0;
}

public function runDiscordRestartCommand() {
  if ($this->sendDiscordRestartNotification())
    echo "discord restart notification sent" . PHP_EOL;
  else
    echo "discord restart notification skipped" . PHP_EOL;
  return 0;
}
}
