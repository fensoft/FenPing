<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/oui.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/ping.php';
require_once __DIR__ . '/hosts.php';
require_once __DIR__ . '/scans.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/backup.php';

$command = $argv[1] ?? '';
if ($command === 'ping') {
  exit(runLockedCliCommand(
    '/tmp/ping.lck',
    'ping scan',
    fn() => runPingCommand(array_slice($argv, 2))
  ));
}

if ($command === 'hosts') {
  exit(runHostsCommand(array_slice($argv, 2)));
}

if ($command === 'inventory') {
  $args = array_slice($argv, 2);
  if (($args[0] ?? '') === '--work') {
    exit(runLockedCliCommand(
      '/tmp/fenping-inventory-worker.lck',
      'inventory worker',
      fn() => runInventoryCommand($args)
    ));
  }
  if (($args[0] ?? '') === '--run-job')
    exit(runInventoryCommand($args));
  exit(runLockedCliCommand(
    '/tmp/inventory-discovery.lck',
    'inventory scheduling',
    fn() => runInventoryCommand($args)
  ));
}

if ($command === 'scan-port-backfill') {
  exit(runScanPortBackfillCommand());
}

if ($command === 'oui-refresh') {
  exit(runLockedCliCommand(
    '/tmp/oui-refresh.lck',
    'OUI refresh',
    fn() => runIeeeOuiRefreshCommand(array_slice($argv, 2))
  ));
}

if ($command === 'oui-sync') {
  exit(runIeeeOuiSyncCommand(array_slice($argv, 2)));
}

if ($command === 'dnsmasq-leases') {
  $args = array_slice($argv, 2);
  exit(runLockedCliCommand(
    '/tmp/dnsmasq-leases.lck',
    'dnsmasq lease import',
    fn() => runDnsmasqLeasesCommand($args)
  ));
}

if ($command === 'discord-restart') {
  exit(runDiscordRestartCommand());
}

if ($command === 'backup') {
  exit(runBackupCommand(array_slice($argv, 2)));
}

if ($command === 'restore') {
  exit(runRestoreCommand(array_slice($argv, 2)));
}

fwrite(STDERR, "Usage: php cli.php ping [1-254|DEBUG]" . PHP_EOL);
fwrite(STDERR, "       php cli.php hosts" . PHP_EOL);
fwrite(STDERR, "       php cli.php inventory [--profile lightweight|standard|deep] [1-254|IPv4] (queue scans)" . PHP_EOL);
fwrite(STDERR, "       php cli.php inventory --work" . PHP_EOL);
fwrite(STDERR, "       php cli.php scan-port-backfill" . PHP_EOL);
fwrite(STDERR, "       php cli.php oui-refresh" . PHP_EOL);
fwrite(STDERR, "       php cli.php oui-sync" . PHP_EOL);
fwrite(STDERR, "       php cli.php dnsmasq-leases" . PHP_EOL);
fwrite(STDERR, "       php cli.php discord-restart" . PHP_EOL);
fwrite(STDERR, "       php cli.php backup [backup.tgz]" . PHP_EOL);
fwrite(STDERR, "       php cli.php restore <backup.tgz>" . PHP_EOL);
exit(2);

function runLockedCliCommand(string $path, string $label, callable $callback): int {
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

function runScanPortBackfillCommand() {
  $inserted = scanPortChangesBackfill();
  echo "scan port changes backfill: $inserted inserted" . PHP_EOL;
  return 0;
}

function runDnsmasqLeasesCommand(array $args): int {
  if (count($args) !== 0) {
    fwrite(STDERR, "Usage: php cli.php dnsmasq-leases" . PHP_EOL);
    return 2;
  }

  try {
    require __DIR__ . '/dnsmasq.leases.php';
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

function runPingCommand($args) {
  global $network, $interface, $myself;

  $arg = $args[0] ?? '';
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
    $targets[$i] = $network . "." . $i;

  $notifyAfterId = discordNotificationsEnabled() ? statsMaxId() : null;
  $hosts = pingHosts($targets, $interface ?? '', array_filter(array_unique(array(
    getenv('IP') ?: '',
    $myself ?? ''
  ))));
  sendDiscordStatusChangesSince($notifyAfterId);

  if ($debug) {
    foreach ($hosts as $host)
      echo $host["ip"] . " " . $host["status"] . PHP_EOL;
  }

  return 0;
}

function runDiscordRestartCommand() {
  if (sendDiscordRestartNotification())
    echo "discord restart notification sent" . PHP_EOL;
  else
    echo "discord restart notification skipped" . PHP_EOL;
  return 0;
}
