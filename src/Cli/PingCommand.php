<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Anomaly\NetworkAnomalyService;
use FenPing\Config\AppConfig;
use FenPing\Discord\DiscordNotifier;
use FenPing\Ipam\IpConflictScanner;
use FenPing\Ipam\IpConflictService;
use FenPing\Network\NetworkManager;
use FenPing\Ping\PingRepository;
use FenPing\Ping\PingScannerGateway;
use FenPing\Status\NotificationService;
use Throwable;

final readonly class PingCommand implements Command
{
    private const DOWN_RETRY_ATTEMPTS = 3;

    public function __construct(
        private AppConfig $config,
        private NetworkManager $networks,
        private PingScannerGateway $scanner,
        private PingRepository $repository,
        private NotificationService $notifications,
        private DiscordNotifier $discord,
        private IpConflictScanner $conflictDetector,
        private IpConflictService $conflicts,
        private NetworkAnomalyService $anomalies,
    ) {
    }

public function run(array $args): int {
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

  $notifyAfterId = $this->notifications->statusChangesEnabled() ? $this->discord->statsMaxId() : null;
  $conflictScan = $this->conflictDetector->scan($selectedNetwork);
  if (!$conflictScan['successful'])
    fwrite(STDERR, 'IP conflict scan failed: ' . $conflictScan['error'] . PHP_EOL);
  else
    $this->notifications->sendIpConflictChanges($this->conflicts->transitionDetails($conflictScan['transitions']));

  $localIps = array_values(array_filter(array_unique(array(
    getenv('IP') ?: '',
    $this->config->applianceIp ?? ''
  ))));
  $hosts = $this->verifiedHosts($targets, $localIps);
  $this->repository->save($hosts);
  if ($from === 1 && $to === 254)
    $this->notifications->sendAnomalyChanges($this->anomalies->observePing($selectedNetwork, $hosts));
  $this->notifications->sendStatusChangesSince($notifyAfterId);

  if ($debug) {
    foreach ($hosts as $host)
      echo $host["ip"] . " " . $host["status"] . PHP_EOL;
  }

  return 0;
}

private function verifiedHosts(array $targets, array $localIps): array {
  $previousStatuses = $this->repository->statusesByIp(array_values($targets));
  $hosts = $this->scanner->scan($targets, $localIps);
  $hostIndexes = array();
  $retryTargets = array();

  foreach ($hosts as $index => $host) {
    $ip = (string)($host['ip'] ?? '');
    $hostIndexes[$ip] = $index;
    if (($previousStatuses[$ip] ?? null) === 'Up'
        && (string)($host['status'] ?? '') !== 'Up')
      $retryTargets[$ip] = $ip;
  }

  for ($attempt = 0; $attempt < self::DOWN_RETRY_ATTEMPTS && $retryTargets !== array(); $attempt++) {
    foreach ($this->scanner->scan(array_values($retryTargets), $localIps) as $host) {
      $ip = (string)($host['ip'] ?? '');
      if (!isset($retryTargets[$ip]) || !isset($hostIndexes[$ip]))
        continue;
      $hosts[$hostIndexes[$ip]] = $host;
      if ((string)($host['status'] ?? '') === 'Up')
        unset($retryTargets[$ip]);
    }
  }

  return $hosts;
}

}
