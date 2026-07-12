<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;

trait RoutesSystemBehavior
{
public function systemApiRoutes(): array {
  return array(
    $this->apiRoute('GET', '/health', 'handleHealth'),
    $this->apiRoute('GET', '/inventory', 'handleInventory'),
    $this->apiRoute('GET', '/notify', 'handleNotify'),
    $this->apiRoute('POST', '/ping/refresh', 'handlePingRefresh', 'session')
  );
}

public function handleHealth(array $params): array {
  return $this->getHealth();
}

public function handleInventory(array $params): array {
  return array(
    'network' => $this->config->network,
    'hosts' => $this->getInventory()
  );
}

public function handleNotify(array $params): array {
  return $this->get_notify();
}

public function handlePingRefresh(array $params): array {
  $command = '/usr/bin/doas /usr/bin/php ' . escapeshellarg($this->config->projectDir . '/cli.php') . ' ping';
  $output = array();
  $code = 0;
  exec($command . ' 2>&1', $output, $code);

  if ($code !== 0)
    $this->jsonError(409, trim(implode("\n", $output)) ?: 'scan already running');

  return array('status' => 'complete');
}
}
