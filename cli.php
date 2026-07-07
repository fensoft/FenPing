<?php
require __DIR__ . '/config.php';
require __DIR__ . '/database.php';
require __DIR__ . '/ping.php';
require __DIR__ . '/hosts.php';

$command = $argv[1] ?? '';
if ($command === 'ping') {
  exit(runPingCommand(array_slice($argv, 2)));
}

if ($command === 'hosts') {
  exit(runHostsCommand());
}

fwrite(STDERR, "Usage: php cli.php ping [1-254|DEBUG]" . PHP_EOL);
fwrite(STDERR, "       php cli.php hosts" . PHP_EOL);
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

  $hosts = pingHosts($targets, $interface ?? '', array_filter(array_unique(array(
    getenv('IP') ?: '',
    $myself ?? ''
  ))));

  if ($debug) {
    foreach ($hosts as $host)
      echo $host["ip"] . " " . $host["status"] . PHP_EOL;
  }

  return 0;
}
