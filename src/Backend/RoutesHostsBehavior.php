<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Network\NetworkPolicyException;

trait RoutesHostsBehavior
{
public function hostsApiRoutes(): array {
  return array(
    $this->apiRoute('GET', '/history/{ip:ipv4}', 'handleHostHistory'),
    $this->apiRoute('GET', '/hosts/{id:int}/detail', 'handleHostDetail'),
    $this->apiRoute('GET', '/hosts/by-ip/{ip:ipv4}/detail', 'handleHostDetailByIp'),
    $this->apiRoute('GET', '/hosts/{id:int}', 'handleHostGet'),
    $this->apiRoute('POST', '/hosts', 'handleHostCreate', 'body', array('live' => array(LiveUpdateScope::Hosts))),
    $this->apiRoute('PUT', '/hosts/{id:int}/metadata', 'handleNamedNonDhcpHostMetadataEdit', 'body', array('live' => array(LiveUpdateScope::Hosts))),
    $this->apiRoute('PUT', '/hosts/{id:int}', 'handleHostEdit', 'body', array('live' => array(LiveUpdateScope::Hosts))),
    $this->apiRoute('DELETE', '/hosts/{id:int}', 'handleHostDelete', 'body', array('live' => array(LiveUpdateScope::Hosts))),
    $this->apiRoute('POST', '/categories', 'handleCategoryCreate', 'body', array('live' => array(LiveUpdateScope::Hosts))),
    $this->apiRoute('PUT', '/categories', 'handleCategoryRename', 'body', array('live' => array(LiveUpdateScope::Hosts))),
    $this->apiRoute('DELETE', '/categories', 'handleCategoryDelete', 'body', array('live' => array(LiveUpdateScope::Hosts)))
  );
}

public function handleHostHistory(array $params): array {
  return $this->get_history_response($params['ip']);
}

public function handleHostGet(array $params): array {
  $host = $this->getId($params['id']);
  if ($host === false)
    $this->jsonError(404, 'host not found');
  return $host;
}

public function handleHostDetail(array $params): array {
  $host = $this->getId($params['id']);
  if ($host === false)
    $this->jsonError(404, 'host not found');

  return $this->buildHostDetailResponse($this->normalizeHostDetail($host));
}

public function handleHostDetailByIp(array $params): array {
  $ip = $params['ip'];
  $identity = null;
  $requestedNetwork = $_GET['network'] ?? null;
  $requestedContainer = $_GET['container'] ?? null;
  if ($requestedNetwork !== null || $requestedContainer !== null) {
    try {
      $identity = array(
        'network' => $this->normalizeInventoryDeviceIdentityPart($requestedNetwork, 'network name'),
        'container' => $this->normalizeInventoryDeviceIdentityPart($requestedContainer, 'container name')
      );
    } catch (InvalidArgumentException $error) {
      $this->jsonError(400, $error->getMessage());
    }
  }
  try {
    $network = $this->networks->forIp($ip, false);
  } catch (NetworkPolicyException $error) {
    $this->jsonError($error->httpStatus, $error->getMessage());
  }
  $inventoryHost = null;
  foreach ($this->getInventory($network->cidr) as $candidate) {
    if (($candidate['ip'] ?? '') !== $ip)
      continue;
    if ($identity !== null && (
      ($candidate['device_identity']['network'] ?? null) !== $identity['network']
      || ($candidate['device_identity']['container'] ?? null) !== $identity['container']
    ))
      continue;
    $inventoryHost = $candidate;
    break;
  }

  if ($inventoryHost === null)
    $this->jsonError(404, 'host not found');

  if (($inventoryHost['id'] ?? null) !== null)
    return $this->handleHostDetail(array('id' => (int)$inventoryHost['id']));

  return $this->buildHostDetailResponse($this->normalizeUnmanagedHostDetail($inventoryHost));
}

public function buildHostDetailResponse(array $host): array {
  $ip = (string)($host['ip'] ?? '');

  $history = $ip !== '' ? $this->get_history_response($ip) : array('summary' => null, 'rows' => array());
  $scans = $ip !== '' ? $this->scanMetadataForIp($ip, 50) : array();
  $latestScan = $ip !== '' ? $this->scanMetadataLatest($ip) : null;
  if ($latestScan !== null) {
    $latestScan['xml_usable'] = $this->scanMetadataXmlUsable($latestScan);
    $latestScan['xml_url'] = $latestScan['xml_usable'] ? $this->scanXmlUrl($latestScan['ip'], $latestScan['id']) : null;
  }
  $netbootImage = null;

  if (!empty($host['netboot_image_id'])) {
    $netbootImage = $this->get_netboot_image((int)$host['netboot_image_id']);
    if ($netbootImage === false)
      $netbootImage = null;
  }

  return array(
    'host' => $host,
    'history' => $history,
    'scans' => $scans,
    'latest_scan' => $latestScan,
    'netboot_image' => $netbootImage
  );
}

public function handleHostCreate(array $params): array {
  $body = $this->requestBody();
  $ip = $this->normalizeHostIp($body['ip'] ?? null);

  try {
    $values = $this->validateDhcpHostCreate($ip, $body['mac'] ?? '');
  } catch (InvalidArgumentException $e) {
    $this->jsonError(400, $e->getMessage());
  }
  $source = null;
  if (array_key_exists('source_device', $body)) {
    if (!is_array($body['source_device']))
      $this->jsonError(400, 'source device must be an object');
    try {
      $source = array(
        'network' => $this->normalizeInventoryDeviceIdentityPart($body['source_device']['network'] ?? null, 'network name'),
        'container' => $this->normalizeInventoryDeviceIdentityPart($body['source_device']['container'] ?? null, 'container name')
      );
    } catch (InvalidArgumentException $e) {
      $this->jsonError(400, $e->getMessage());
    }
    if ($this->dockerContainerIdentity($source['network'], $source['container'], (string)$values['ip']) === null)
      $this->jsonError(409, 'source container identity is no longer available at this IP');
  }

  try {
    $change = $this->commitDhcpMutation(function () use ($values, $source) {
      $id = (int)$this->create($values['ip'], $values['mac']);
      if ($source !== null)
        $this->transferInventoryDeviceMetadataToHost($source['network'], $source['container'], $id);
      return $id;
    });
  } catch (PDOException $e) {
    $this->handleHostConstraintError($e);
  }

  return array('id' => (int)$change['result'], 'log' => $change['log']);
}

public function handleHostEdit(array $params): array {
  $body = $this->requestBody();
  $id = $params['id'];
  $existing = $this->getId($id);
  if ($existing === false)
    $this->jsonError(404, 'host not found');
  try {
    $this->networks->assertDhcpIp((string)($existing['ip'] ?? ''));
  } catch (NetworkPolicyException $error) {
    $this->jsonError($error->httpStatus, $error->getMessage());
  }
  $ip = $this->normalizeHostIp($body['ip'] ?? null);
  $netbootImageId = $this->normalizeNetbootImageId($body['netboot_image_id'] ?? null);

  try {
    $values = $this->validateDhcpHostEdit(
      $ip,
      $body['mac'] ?? '',
      $body['name'] ?? '',
      $body['router'] ?? null,
      $body['dns'] ?? null
    );
    $scanProfile = $this->normalizeScheduledScanProfile($body['scan_profile'] ?? $existing['scan_profile'] ?? self::SCAN_MANAGED_DEFAULT_PROFILE);
    $scanIntervalHours = $this->normalizeScanIntervalHours($body['scan_interval_hours'] ?? $existing['scan_interval_hours'] ?? self::SCAN_MANAGED_DEFAULT_INTERVAL_HOURS);
    $displayName = $this->normalizeHostMetadataText(
      $body['display_name'] ?? $existing['display_name'] ?? '', 'display name'
    );
    $notes = $this->normalizeHostNotes($body['notes'] ?? $existing['notes'] ?? '');
    $location = $this->normalizeHostMetadataText($body['location'] ?? $existing['location'] ?? '', 'location');
    $owner = $this->normalizeHostMetadataText($body['owner'] ?? $existing['owner'] ?? '', 'owner');
    $model = $this->normalizeHostMetadataText($body['model'] ?? $existing['model'] ?? '', 'model');
    $icon = $this->normalizeHostIcon(array_key_exists('icon', $body) ? $body['icon'] : ($existing['icon'] ?? null));
    $tags = array_key_exists('tags', $body)
      ? $this->normalizeHostTags($body['tags'])
      : $this->normalizeHostTags($existing['tags'] ?? array());
  } catch (InvalidArgumentException $e) {
    $this->jsonError(400, $e->getMessage());
  }

  try {
    $change = $this->commitDhcpMutation(function () use ($id, $values, $body, $netbootImageId, $scanProfile, $scanIntervalHours, $displayName, $notes, $location, $owner, $model, $icon, $tags) {
      if ($this->getId($id) === false)
        throw new DhcpHostNotFoundException('host not found');
      if ($netbootImageId !== null && !$this->netboot_image_exists($netbootImageId))
        throw new DhcpHostInputException('invalid netboot image');

      $this->edit(
        $id,
        $values['ip'],
        $values['mac'],
        $values['name'],
        $this->toDbFlag($body['repeater'] ?? null),
        $this->toDbFlag($body['important'] ?? null),
        $this->toDbFlag($body['web'] ?? null),
        $values['router'],
        $values['dns'],
        $netbootImageId,
        $scanProfile,
        $scanIntervalHours,
        $notes,
        $location,
        $owner,
        $model,
        $icon,
        $tags,
        $displayName
      );
      return true;
    });
  } catch (DhcpHostNotFoundException $e) {
    $this->jsonError(404, $e->getMessage());
  } catch (DhcpHostInputException $e) {
    $this->jsonError(400, $e->getMessage());
  } catch (PDOException $e) {
    $this->handleHostConstraintError($e);
  }

  return array('saved' => true, 'log' => $change['log']);
}

public function handleHostDelete(array $params): array {
  $id = $params['id'];
  $existing = $this->getId($id);
  if ($existing === false)
    $this->jsonError(404, 'host not found');
  try {
    $this->networks->assertDhcpIp((string)($existing['ip'] ?? ''));
  } catch (NetworkPolicyException $error) {
    $this->jsonError($error->httpStatus, $error->getMessage());
  }

  try {
    $change = $this->commitDhcpMutation(function () use ($id) {
      if ($this->getId($id) === false)
        throw new DhcpHostNotFoundException('host not found');
      $this->del($id);
      return true;
    });
  } catch (DhcpHostNotFoundException $e) {
    $this->jsonError(404, $e->getMessage());
  }

  return array('deleted' => true, 'log' => $change['log']);
}

public function handleHostConstraintError(PDOException $error): void {
  if ((string)$error->getCode() === '23000')
    $this->jsonError(409, 'host name, MAC address, and IP address must be unique');
  throw $error;
}

public function handleCategoryCreate(array $params): array {
  $body = $this->requestBody();
  $this->assertCategoryDhcpNetwork($body['ip'] ?? '');
  $this->addCategory($body['ip'] ?? '', $body['name'] ?? '');
  return array('created' => true);
}

public function handleCategoryRename(array $params): array {
  $body = $this->requestBody();
  $this->assertCategoryDhcpNetwork($body['ip'] ?? '');
  try {
    $updated = $this->renameCategory($body['ip'] ?? '', $body['name'] ?? '');
  } catch (InvalidArgumentException $e) {
    $this->jsonError(400, $e->getMessage());
  }

  if ($updated < 1)
    $this->jsonError(404, 'category not found');

  return array('renamed' => true);
}

public function handleCategoryDelete(array $params): array {
  $body = $this->requestBody();
  $this->assertCategoryDhcpNetwork($body['ip'] ?? '');
  $this->delCategory($body['ip'] ?? '');
  return array('deleted' => true);
}

public function assertCategoryDhcpNetwork($value): void {
  $ip = $this->normalizeCategoryIp($value);
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    $this->jsonError(400, 'invalid category ip');
  try {
    $this->networks->assertDhcpIp($ip);
  } catch (NetworkPolicyException $error) {
    $this->jsonError($error->httpStatus, $error->getMessage());
  }
}

public function normalizeHostDetail(array $host): array {
  $host['id'] = (int)$host['id'];
  $host['important'] = (int)($host['important'] ?? 0);
  $host['repeater'] = (int)($host['repeater'] ?? 0);
  $host['web'] = (int)($host['web'] ?? 0);
  $host['netboot_image_id'] = $host['netboot_image_id'] === null ? null : (int)$host['netboot_image_id'];
  $host['scan_profile'] = $this->normalizeScheduledScanProfile($host['scan_profile'] ?? self::SCAN_MANAGED_DEFAULT_PROFILE);
  $host['scan_interval_hours'] = $this->normalizeScanIntervalHours($host['scan_interval_hours'] ?? self::SCAN_MANAGED_DEFAULT_INTERVAL_HOURS);
  $host['mac'] = strtolower((string)($host['mac'] ?? ''));
  $host['vendor'] = $this->hostVendorFromCache($host['mac']);
  $host['dhcp_managed'] = 1;
  $host['network_is_dhcp'] = $this->config->dhcpNetwork->contains((string)($host['ip'] ?? '')) ? 1 : 0;
  $identity = $this->namedNonDhcpHostIdentity($host);
  $host['device_identity'] = $identity;
  $host['metadata_editable'] = $identity === null ? 0 : 1;
  $ping = $this->hostPingState($host);
  $host['status'] = $ping['status'];
  $host['date'] = $ping['date'];
  $host = $this->withAutomaticInventoryTags(
    $host,
    $this->dockerAutomaticInventoryTagsForIp((string)($host['ip'] ?? ''))
  );
  return $host;
}

public function normalizeUnmanagedHostDetail(array $host): array {
  $host = $this->normalizeInventoryRow($host, $host['ip'] ?? '');
  $host['id'] = null;
  $host['important'] = (int)($host['important'] ?? 0);
  $host['repeater'] = (int)($host['repeater'] ?? 0);
  $host['web'] = (int)($host['web'] ?? 0);
  $host['approved'] = (int)($host['approved'] ?? 0);
  $host['is_new'] = (int)($host['is_new'] ?? 0);
  $host['mac'] = strtolower((string)($host['mac'] ?? ''));
  $host['vendor'] = (string)($host['vendor'] ?? $this->getVendor($host['mac']));
  $host['router'] = '';
  $host['dns'] = '';
  $host['netboot_image_id'] = null;
  $host['dhcp_managed'] = 0;
  $host['network_is_dhcp'] = $this->config->dhcpNetwork->contains((string)($host['ip'] ?? '')) ? 1 : 0;
  return $host;
}

public function hostVendorFromCache(string $mac): string {
  return $this->getVendor($mac);
}

public function hostPingState(array $host): array {
  $ip = (string)($host['ip'] ?? '');
  $mac = strtolower((string)($host['mac'] ?? ''));
  if ($ip === '' && $mac === '')
    return array('status' => '', 'date' => null);

  $stmt = $this->getDb()->prepare("
    SELECT status, date
    FROM ping
    WHERE (:ip<>'' AND ip=:ip)
       OR (:mac<>'' AND LOWER(mac)=:mac)
    ORDER BY CASE WHEN ip=:ip THEN 0 ELSE 1 END, date DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'mac' => $mac));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row === false)
    return array('status' => '', 'date' => null);
  return array(
    'status' => $row['status'] ?? '',
    'date' => $row['date'] ?? null
  );
}
}
