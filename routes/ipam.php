<?php

function ipamApiRoutes(): array {
  return array(
    apiRoute('GET', '/ipam', 'handleIpamGet'),
    apiRoute('PUT', '/ipam/devices/{mac}/approval', 'handleIpamApprove', 'session'),
    apiRoute('DELETE', '/ipam/devices/{mac}/approval', 'handleIpamUnapprove', 'session')
  );
}

function handleIpamGet(array $params): array {
  return getIpam();
}

function handleIpamApprove(array $params): array {
  return approveDevice(ipamRouteMac($params['mac']));
}

function handleIpamUnapprove(array $params): array {
  return unapproveDevice(ipamRouteMac($params['mac']));
}

function ipamRouteMac($value): string {
  try {
    return normalizeDhcpMac($value, true);
  } catch (InvalidArgumentException $e) {
    jsonError(400, $e->getMessage());
  }
}
