<?php

function hostsApiRoutes(): array {
  return array(
    apiRoute('GET', '/history/{ip:ipv4}', 'handleHostHistory'),
    apiRoute('GET', '/hosts/{id:int}/detail', 'handleHostDetail'),
    apiRoute('GET', '/hosts/{id:int}', 'handleHostGet'),
    apiRoute('POST', '/hosts', 'handleHostCreate', 'body'),
    apiRoute('PUT', '/hosts/{id:int}', 'handleHostEdit', 'body'),
    apiRoute('DELETE', '/hosts/{id:int}', 'handleHostDelete', 'body'),
    apiRoute('POST', '/categories', 'handleCategoryCreate', 'body'),
    apiRoute('PUT', '/categories', 'handleCategoryRename', 'body'),
    apiRoute('DELETE', '/categories', 'handleCategoryDelete', 'body')
  );
}

function handleHostHistory(array $params): array {
  return get_history_response($params['ip']);
}

function handleHostGet(array $params): array {
  $host = getId($params['id']);
  if ($host === false)
    jsonError(404, 'host not found');
  return $host;
}

function handleHostDetail(array $params): array {
  $host = getId($params['id']);
  if ($host === false)
    jsonError(404, 'host not found');

  $host = normalizeHostDetail($host);
  $ip = (string)($host['ip'] ?? '');

  $history = $ip !== '' ? get_history_response($ip) : array('summary' => null, 'rows' => array());
  $scans = $ip !== '' ? scanMetadataForIp($ip, 50) : array();
  $latestScan = $ip !== '' ? scanMetadataLatest($ip) : null;
  if ($latestScan !== null) {
    $latestScan['xml_usable'] = scanMetadataXmlUsable($latestScan);
    $latestScan['xml_url'] = $latestScan['xml_usable'] ? scanXmlUrl($latestScan['ip'], $latestScan['id']) : null;
  }
  $netbootImage = null;

  if (!empty($host['netboot_image_id'])) {
    $netbootImage = get_netboot_image((int)$host['netboot_image_id']);
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

function handleHostCreate(array $params): array {
  $body = requestBody();
  $ip = normalizeHostIp($body['ip'] ?? null);

  try {
    $values = validateDhcpHostCreate($ip, $body['mac'] ?? '');
  } catch (InvalidArgumentException $e) {
    jsonError(400, $e->getMessage());
  }

  try {
    $change = commitDhcpMutation(fn() => create($values['ip'], $values['mac']));
  } catch (PDOException $e) {
    handleHostConstraintError($e);
  }

  return array('id' => (int)$change['result'], 'log' => $change['log']);
}

function handleHostEdit(array $params): array {
  $body = requestBody();
  $id = $params['id'];
  $existing = getId($id);
  if ($existing === false)
    jsonError(404, 'host not found');
  $ip = normalizeHostIp($body['ip'] ?? null);
  $netbootImageId = normalizeNetbootImageId($body['netboot_image_id'] ?? null);

  try {
    $values = validateDhcpHostEdit(
      $ip,
      $body['mac'] ?? '',
      $body['name'] ?? '',
      $body['router'] ?? null,
      $body['dns'] ?? null
    );
    $scanProfile = normalizeScheduledScanProfile($body['scan_profile'] ?? $existing['scan_profile'] ?? 'deep');
    $scanIntervalHours = normalizeScanIntervalHours($body['scan_interval_hours'] ?? $existing['scan_interval_hours'] ?? 1);
  } catch (InvalidArgumentException $e) {
    jsonError(400, $e->getMessage());
  }

  try {
    $change = commitDhcpMutation(function () use ($id, $values, $body, $netbootImageId, $scanProfile, $scanIntervalHours) {
      if (getId($id) === false)
        throw new DhcpHostNotFoundException('host not found');
      if ($netbootImageId !== null && !netboot_image_exists($netbootImageId))
        throw new DhcpHostInputException('invalid netboot image');

      edit(
        $id,
        $values['ip'],
        $values['mac'],
        $values['name'],
        toDbFlag($body['repeater'] ?? null),
        toDbFlag($body['important'] ?? null),
        toDbFlag($body['web'] ?? null),
        $values['router'],
        $values['dns'],
        $netbootImageId,
        $scanProfile,
        $scanIntervalHours
      );
      return true;
    });
  } catch (DhcpHostNotFoundException $e) {
    jsonError(404, $e->getMessage());
  } catch (DhcpHostInputException $e) {
    jsonError(400, $e->getMessage());
  } catch (PDOException $e) {
    handleHostConstraintError($e);
  }

  return array('saved' => true, 'log' => $change['log']);
}

function handleHostDelete(array $params): array {
  $id = $params['id'];

  try {
    $change = commitDhcpMutation(function () use ($id) {
      if (getId($id) === false)
        throw new DhcpHostNotFoundException('host not found');
      del($id);
      return true;
    });
  } catch (DhcpHostNotFoundException $e) {
    jsonError(404, $e->getMessage());
  }

  return array('deleted' => true, 'log' => $change['log']);
}

function handleHostConstraintError(PDOException $error): void {
  if ((string)$error->getCode() === '23000')
    jsonError(409, 'host name, MAC address, and IP address must be unique');
  throw $error;
}

function handleCategoryCreate(array $params): array {
  $body = requestBody();
  addCategory($body['ip'] ?? '', $body['name'] ?? '');
  return array('created' => true);
}

function handleCategoryRename(array $params): array {
  $body = requestBody();
  try {
    $updated = renameCategory($body['ip'] ?? '', $body['name'] ?? '');
  } catch (InvalidArgumentException $e) {
    jsonError(400, $e->getMessage());
  }

  if ($updated < 1)
    jsonError(404, 'category not found');

  return array('renamed' => true);
}

function handleCategoryDelete(array $params): array {
  $body = requestBody();
  delCategory($body['ip'] ?? '');
  return array('deleted' => true);
}

function normalizeHostDetail(array $host): array {
  $host['id'] = (int)$host['id'];
  $host['important'] = (int)($host['important'] ?? 0);
  $host['repeater'] = (int)($host['repeater'] ?? 0);
  $host['web'] = (int)($host['web'] ?? 0);
  $host['netboot_image_id'] = $host['netboot_image_id'] === null ? null : (int)$host['netboot_image_id'];
  $host['scan_profile'] = normalizeScheduledScanProfile($host['scan_profile'] ?? 'deep');
  $host['scan_interval_hours'] = normalizeScanIntervalHours($host['scan_interval_hours'] ?? 1);
  $host['mac'] = strtolower((string)($host['mac'] ?? ''));
  $host['vendor'] = hostVendorFromCache($host['mac']);
  $ping = hostPingState($host);
  $host['status'] = $ping['status'];
  $host['date'] = $ping['date'];
  return $host;
}

function hostVendorFromCache(string $mac): string {
  return getVendor($mac);
}

function hostPingState(array $host): array {
  $ip = (string)($host['ip'] ?? '');
  $mac = strtolower((string)($host['mac'] ?? ''));
  if ($ip === '' && $mac === '')
    return array('status' => '', 'date' => null);

  $stmt = getDb()->prepare("
    SELECT status, date
    FROM ping
    WHERE (:ip<>'' AND ip=:ip)
       OR (:mac<>'' AND LOWER(mac) COLLATE latin1_general_ci=:mac)
    ORDER BY IF(ip=:ip, 0, 1), date DESC
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
