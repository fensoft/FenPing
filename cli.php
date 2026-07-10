<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/ping.php';
require_once __DIR__ . '/hosts.php';
require_once __DIR__ . '/scans.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/backup.php';

$command = $argv[1] ?? '';
if ($command === 'ping') {
  exit(runPingCommand(array_slice($argv, 2)));
}

if ($command === 'hosts') {
  exit(runHostsCommand());
}

if ($command === 'inventory') {
  exit(runInventoryCommand(array_slice($argv, 2)));
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
fwrite(STDERR, "       php cli.php inventory [--quick] [1-254|IPv4]" . PHP_EOL);
fwrite(STDERR, "       php cli.php discord-restart" . PHP_EOL);
fwrite(STDERR, "       php cli.php backup [backup.tgz]" . PHP_EOL);
fwrite(STDERR, "       php cli.php restore <backup.tgz|dump.sql.gz>" . PHP_EOL);
exit(2);

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
