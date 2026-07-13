<?php

declare(strict_types=1);

namespace FenPing\Backend;

use FenPing\Realtime\LiveUpdateScope;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;

trait RoutesIpamBehavior
{
public function ipamApiRoutes(): array {
  return array(
    $this->apiRoute('GET', '/ipam', 'handleIpamGet'),
    $this->apiRoute('GET', '/ipam/conflicts', 'handleIpamConflictsGet'),
    $this->apiRoute('PUT', '/ipam/devices/{mac}/approval', 'handleIpamApprove', 'session', array('live' => array(LiveUpdateScope::Hosts))),
    $this->apiRoute('DELETE', '/ipam/devices/{mac}/approval', 'handleIpamUnapprove', 'session', array('live' => array(LiveUpdateScope::Hosts)))
  );
}

public function handleIpamGet(array $params): array {
  return $this->getIpam();
}

public function handleIpamConflictsGet(array $params): array {
  return $this->getIpConflictStatus();
}

public function handleIpamApprove(array $params): array {
  return $this->approveDevice($this->ipamRouteMac($params['mac']));
}

public function handleIpamUnapprove(array $params): array {
  return $this->unapproveDevice($this->ipamRouteMac($params['mac']));
}

public function ipamRouteMac($value): string {
  try {
    return $this->normalizeDhcpMac($value, true);
  } catch (InvalidArgumentException $e) {
    $this->jsonError(400, $e->getMessage());
  }
}
}
