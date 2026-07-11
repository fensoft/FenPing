<?php

function systemApiRoutes(): array {
  return array(
    apiRoute('GET', '/health', 'handleHealth'),
    apiRoute('GET', '/inventory', 'handleInventory'),
    apiRoute('GET', '/notify', 'handleNotify'),
    apiRoute('POST', '/ping/refresh', 'handlePingRefresh', 'session')
  );
}

function handleHealth(array $params): array {
  return getHealth();
}

function handleInventory(array $params): array {
  return array(
    'network' => $GLOBALS['network'] ?? '',
    'hosts' => getInventory()
  );
}

function handleNotify(array $params): array {
  return get_notify();
}

function handlePingRefresh(array $params): array {
  $command = '/usr/bin/sudo /usr/bin/php ' . escapeshellarg(dirname(__DIR__) . '/cli.php') . ' ping';
  $output = array();
  $code = 0;
  exec($command . ' 2>&1', $output, $code);

  if ($code !== 0)
    jsonError(409, trim(implode("\n", $output)) ?: 'scan already running');

  return array('status' => 'complete');
}
