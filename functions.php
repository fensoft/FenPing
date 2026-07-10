<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oui.php';

function getVendor($mac) {
  static $cache = array();
  static $databaseAvailable = true;

  $normalized = ieeeOuiNormalizeMac((string)$mac);
  if ($normalized === '')
    return '';
  if (array_key_exists($normalized, $cache))
    return $cache[$normalized];

  $firstOctet = hexdec(substr($normalized, 0, 2));
  if (($firstOctet & 0x02) !== 0)
    return $cache[$normalized] = '';

  if ($databaseAvailable) {
    try {
      $vendor = ieeeOuiDatabaseVendor(getDb(), $normalized);
      if ($vendor !== null)
        return $cache[$normalized] = $vendor;
    } catch (Throwable $e) {
      $databaseAvailable = false;
    }
  }
  return $cache[$normalized] = ieeeOuiVendor($normalized);
}

function getDb() {
  return db();
}

function normalizeCategoryIp($ip) {
  global $network;
  $ip = trim((string)$ip);
  if ($ip != "" && strpos($ip, ".") === false)
    return $network . "." . $ip;
  return $ip;
}

function normalizeInventoryRow($data, $ip = null) {
  $defaults = array(
    "id" => null,
    "name" => "",
    "ip" => $ip,
    "mac" => "",
    "status" => "",
    "date" => null,
    "important" => 0,
    "web" => null,
    "repeater" => null
  );
  $row = array_merge($defaults, $data);
  $row["name"] = $row["name"] === null ? "" : $row["name"];
  $row["ip"] = $row["ip"] === null ? "" : $row["ip"];
  $row["mac"] = $row["mac"] === null ? "" : $row["mac"];
  $row["important"] = $row["important"] === null ? 0 : $row["important"];
  return $row;
}

function getInventory() {
  global $myself;

  $db = getDb();
  $latestScans = getLatestScans();
  $approvedDevices = array();
  $approvalStmt = $db->query("SELECT mac FROM device_approvals");
  while ($approvedMac = $approvalStmt->fetchColumn()) {
    $approvedMac = strtolower(trim((string)$approvedMac));
    if ($approvedMac !== '')
      $approvedDevices[$approvedMac] = true;
  }

  $repeater = array();
  $stmt = $db->prepare("select mac, ip, name from ips where repeater=1");
  $stmt->execute();
  while ($data = $stmt->fetch()) {
    $mac = strtolower((string)($data[0] ?? ""));
    if ($mac != "")
      $repeater[$mac] = array("ip" => $data[1], "name" => $data[2]);
  }

  $ips = array();
  #cas normal
  $stmt = $db->prepare("select id, name, i.ip, i.mac, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and i.ip=p.ip and (p.mac=i.mac or p.mac='')");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC))
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  #derri�re un autre routeur
  $stmt = $db->prepare("select id, name, i.ip, p.mac, i.mac as mac_i, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and p.mac!='' and i.ip=p.ip and p.mac!=i.mac");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mac = (string)($data["mac"] ?? "");
    if (isset($repeater[$mac]))
      $data["via"] = "via " . $repeater[$mac]["name"];
    $data["mac"] = $data["mac_i"];
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #mauvaise ip
  $stmt = $db->prepare("select id, name, p.ip, i.ip as ip_should, p.mac, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.ip != i.ip and status = 'Up' and repeater is null");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #en db mais ne pingent pas
  $stmt = $db->prepare("select id, name, p.ip, p.mac, status, date, i.important, i.web, i.repeater from ips i right outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.status != 'Down' and id is null");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #dans les leases avec des new mac
  $macs = array();
  foreach ($ips as $key => $data) {
    $mac = strtoupper((string)($data["mac"] ?? ""));
    if ($mac != "")
      array_push($macs, $mac);
  }
  $stmt = $db->prepare("select NULL as id, `client-hostname` as name, ip, `hardware-ethernet` as mac, 'Down' as status, last_seen as date, 0 as important from leases where active=1 and ends > date_sub(now(), interval 7 day)");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $ip = $data["ip"] ?? "";
    if ($ip == "")
      continue;
    $mac = strtoupper((string)($data["mac"] ?? ""));
    if (!in_array($mac, $macs)) {
      if (!isset($ips[$ip]))
        $ips[$ip] = $data;
    }
    if (isset($ips[$ip]) && ($ips[$ip]["name"] ?? "") == "" && ($data["name"] ?? "") != "")
      $ips[$ip]["name"] = $data["name"];
  }
  #toujours up
  if ($myself != "") {
    if (!isset($ips[$myself]))
      $ips[$myself] = array("ip" => $myself);
    $ips[$myself]["status"] = "Up";
  }
  $sorted_ips = array();
  foreach ($ips as $key => $data) {
    $data = normalizeInventoryRow($data, $key);
    $ipLong = ip2long($data["ip"]);
    if ($ipLong === false)
      continue;
    $sorted_ips[$ipLong] = $data;
  }
  ksort($sorted_ips);
  global $network;
  $old_ip = $network . ".0";
  $res = array();
  $stats = get_stats();
  foreach ($sorted_ips as $key => $data) {
    $ip = $data["ip"];
    $mac = trim((string)$data["mac"]);
    if ($mac == "")
      continue;
    $normalizedMac = strtolower($mac);
    $managed = isset($data['id']) && $data['id'] !== null;
    $data['approved'] = $managed || isset($approvedDevices[$normalizedMac]) ? 1 : 0;
    $data['is_new'] = !$managed && !isset($approvedDevices[$normalizedMac]) ? 1 : 0;
    $stmt2 = $db->prepare("
      select ip_begin, ip_begin_full, type from (
        select
          ip_begin,
          case when ip_begin like '%.%' then ip_begin else concat(:network, '.', ip_begin) end as ip_begin_full,
          type
        from `range`
      ) ranges
      where INET_ATON(:ip_begin) < INET_ATON(ip_begin_full)
        and INET_ATON(:ip_end) >= INET_ATON(ip_begin_full)
      order by INET_ATON(ip_begin_full) desc
      limit 1
    ");
    $stmt2->execute(array("network" => $network, "ip_begin" => $old_ip, "ip_end" => $ip));
    $data2 = $stmt2->fetch();
    if ($data2 != false) {
      $old_ip = $ip;
      $data["category"] = $data2["type"];
      $data["category_ip"] = $data2["ip_begin"];
    }
    $data["vendor"] = "";
    $data["vendor"] = getVendor($mac);
    if ($ip != "" && isset($stats[$ip])) {
      $data["stability"] = $stats[$ip];
      $data["stats"] = $stats[$ip]["label"];
      $data["stats2"] = $stats[$ip]["transitions"];
    }
    if ($ip != "" && !empty($latestScans[$ip]["result_available"])) {
      $data["xml"] = $ip;
    }
    if ($ip != "" && isset($latestScans[$ip])) {
      $data["scan"] = $latestScans[$ip];
    }
    array_push($res, $data);
  }
  return $res;
}

function getLatestScans() {
  $stmt = getDb()->prepare("
    SELECT
      s.id,
      s.ip,
      s.mode,
      s.state,
      s.status,
      s.date_begin,
      s.date_end,
      s.duration,
      s.ports_count,
      s.snapshot_id,
      s.result_changed,
      s.error,
      EXISTS(
        SELECT 1
        FROM scans result
        WHERE result.ip=s.ip
          AND result.state='complete'
          AND result.snapshot_id IS NOT NULL
      ) AS result_available
    FROM scans s
    INNER JOIN (
      SELECT ip, MAX(id) id
      FROM scans
      GROUP BY ip
    ) latest ON latest.id=s.id
  ");
  $stmt->execute();

  $scans = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row["id"] = (int)$row["id"];
    $row["duration"] = $row["duration"] === null ? null : (int)$row["duration"];
    $row["ports_count"] = (int)$row["ports_count"];
    $row["snapshot_id"] = $row["snapshot_id"] === null ? null : (int)$row["snapshot_id"];
    $row["result_changed"] = (int)$row["result_changed"];
    $row["result_available"] = (int)$row["result_available"];
    $row["xml"] = $row["result_available"] ? scanXmlUrl($row["ip"]) : null;
    $scans[$row["ip"]] = $row;
  }
  return $scans;
}

function addCategory($ip, $name) {
  $stmt = getDb()->prepare("INSERT INTO `range` (ip_begin,`type`) VALUES (:ip, :name)");
  $stmt->execute(array("ip" => normalizeCategoryIp($ip), "name" => $name));
}

function renameCategory($category, $name) {
  global $network;
  $category = trim((string)$category);
  if ($category == "")
    throw new InvalidArgumentException("category ip is required");

  $name = trim((string)$name);
  if ($name == "")
    throw new InvalidArgumentException("category name is required");

  $normalized = normalizeCategoryIp($category);
  $short = str_replace($network . ".", "", $normalized);
  $exists = getDb()->prepare("SELECT COUNT(*) FROM `range` WHERE ip_begin=:ip OR ip_begin=:normalized OR ip_begin=:short");
  $exists->execute(array("ip" => $category, "normalized" => $normalized, "short" => $short));
  if ((int)$exists->fetchColumn() < 1)
    return 0;

  $stmt = getDb()->prepare("UPDATE `range` SET `type`=:name WHERE ip_begin=:ip OR ip_begin=:normalized OR ip_begin=:short");
  $stmt->execute(array("name" => $name, "ip" => $category, "normalized" => $normalized, "short" => $short));
  return 1;
}

function delCategory($category) {
  global $network;
  $normalized = normalizeCategoryIp($category);
  $short = str_replace($network . ".", "", $normalized);
  $stmt = getDb()->prepare("DELETE FROM `range` WHERE ip_begin=:ip OR ip_begin=:normalized OR ip_begin=:short");
  $stmt->execute(array("ip" => $category, "normalized" => $normalized, "short" => $short));
}

function getIp($ip) {
  $stmt = getDb()->prepare("select * from ips where ip=:ip");
  $stmt->execute(array("ip" => $ip));
  return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getMac($mac) {
  $stmt = getDb()->prepare("select * from ips where lower(mac)=:mac");
  $stmt->execute(array("mac" => $mac));
  return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getId($id) {
  $stmt = getDb()->prepare("select * from ips where id=:id");
  $stmt->execute(array("id" => $id));
  return $stmt->fetch(PDO::FETCH_ASSOC);
}

function create($ip, $mac) {
  $stmt = getDb()->prepare("INSERT INTO ips (mac,ip) VALUES (:mac,:ip)");
  $stmt->execute(array("mac" => $mac, "ip" => $ip));
  return getDb()->lastInsertId();
}

function edit($id, $ip, $mac, $name, $repeater, $important, $web, $router, $dns, $netbootImageId = null, $scanProfile = 'deep', $scanIntervalHours = 1) {
  $stmt = getDb()->prepare("UPDATE ips SET name=:name, mac=:mac, ip=:ip, repeater=:repeater, important=:important, web=:web, router=:router, dns=:dns, netboot_image_id=:netboot_image_id, scan_profile=:scan_profile, scan_interval_hours=:scan_interval_hours WHERE id=:id");
  $stmt->execute(array("name" => $name, "mac" => $mac, "ip" => $ip, "repeater" => $repeater != "1" ? null : "1", "important" => $important != "1" ? null : "1", "web" => $web != "1" ? null : "1", "router" => $router == "" ? null : $router, "dns" => $dns == "" ? null : $dns, "netboot_image_id" => $netbootImageId, "scan_profile" => $scanProfile, "scan_interval_hours" => $scanIntervalHours, "id" => $id));
  return $stmt->rowCount();
}

function del($id) {
  $stmt = getDb()->prepare("DELETE FROM ips WHERE id=:id");
  $stmt->execute(array("id" => $id));
  return $stmt->rowCount();
}

function get_stats() {
  $stmt = getDb()->prepare("select distinct ip from stats where ip is not null and ip<>'' and date_end > date_sub(now(), interval 7 day)");
  $stmt->execute();
  $arr = array();
  while ($i = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $history = get_history($i["ip"]);
    $summary = get_history_summary($history);
    if (!$summary["stable"])
      $arr[$i["ip"]] = $summary;
  }
  return $arr;
}

function temps($val) {
  if ($val < 60)
    return $val . " s";
  if ($val < 60 * 60)
    return intval($val / 60) . " m";
  if ($val < 60 * 60 * 24)
    return intval($val / (60 * 60)) . " h";
  return intval($val / (60 * 60 * 24)) . " d";
}

function get_history_response($ip) {
  $rows = get_history($ip);
  return array(
    "summary" => get_history_summary($rows),
    "rows" => $rows
  );
}

function get_port_notify($hours = 24) {
  $hours = max(1, min(168, (int)$hours));
  $stmt = getDb()->prepare("
    SELECT
      c.id,
      c.scan_id,
      c.ip,
      c.mode,
      c.change_type,
      c.protocol,
      c.port,
      c.previous_service,
      c.previous_version,
      c.current_service,
      c.current_version,
      c.created_at,
      UNIX_TIMESTAMP(c.created_at) AS created,
      COALESCE(NULLIF((
        SELECT i.mac FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1
      ), ''), NULLIF((
        SELECT s.mac FROM stats s WHERE s.ip=c.ip AND s.mac IS NOT NULL AND s.mac<>'' ORDER BY s.id DESC LIMIT 1
      ), ''), '') AS mac,
      COALESCE(NULLIF((
        SELECT i.name FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1
      ), ''), NULLIF((
        SELECT l.`client-hostname` FROM leases l WHERE l.ip=c.ip ORDER BY l.active DESC, l.last_seen DESC LIMIT 1
      ), ''), '') AS name,
      COALESCE((
        SELECT i.important FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1
      ), 0) AS important
    FROM scan_port_changes c
    WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL $hours HOUR)
    ORDER BY c.created_at DESC, c.id DESC
  ");
  $stmt->execute();

  $changes = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['id'] = (int)$row['id'];
    $row['scan_id'] = (int)$row['scan_id'];
    $row['port'] = (int)$row['port'];
    $row['created'] = (int)$row['created'];
    $row['important'] = (int)$row['important'];
    $row['vendor'] = getVendor((string)($row['mac'] ?? ''));
    $changes[] = $row;
  }
  return $changes;
}

function get_notify($hours = 24) {
  global $network;
  $hours = max(1, min(168, (int)$hours));
  $stmt = getDb()->prepare("
    SELECT
      s.id,
      s.ip,
      COALESCE(NULLIF(s.mac, ''), (
        SELECT known.mac
        FROM stats known
        WHERE known.ip=s.ip AND known.mac IS NOT NULL AND known.mac<>''
        ORDER BY known.id DESC
        LIMIT 1
      ), (
        SELECT i.mac
        FROM ips i
        WHERE i.ip=s.ip AND i.mac IS NOT NULL AND i.mac<>''
        ORDER BY i.id DESC
        LIMIT 1
      ), '') AS mac,
      s.status,
      s.date_begin,
      s.date_end,
      UNIX_TIMESTAMP(s.date_begin) AS `begin`,
      UNIX_TIMESTAMP(CASE
        WHEN s.id=(SELECT MAX(latest.id) FROM stats latest WHERE latest.ip=s.ip) THEN NOW()
        ELSE COALESCE(s.date_end, NOW())
      END) AS `end`,
      GREATEST(0, UNIX_TIMESTAMP(CASE
        WHEN s.id=(SELECT MAX(latest.id) FROM stats latest WHERE latest.ip=s.ip) THEN NOW()
        ELSE COALESCE(s.date_end, NOW())
      END) - UNIX_TIMESTAMP(s.date_begin)) AS duration,
      IF(s.id=(SELECT MAX(latest.id) FROM stats latest WHERE latest.ip=s.ip), 1, 0) AS current,
      (SELECT prev.status FROM stats prev WHERE prev.ip=s.ip AND prev.id<s.id ORDER BY prev.id DESC LIMIT 1) AS previous_status,
      COALESCE(NULLIF((
        SELECT i.name
        FROM ips i
        WHERE i.ip=s.ip OR LOWER(i.mac) COLLATE latin1_general_ci=LOWER(s.mac) COLLATE latin1_general_ci
        ORDER BY IF(i.ip=s.ip, 0, 1), i.id DESC
        LIMIT 1
      ), ''), NULLIF((
        SELECT l.`client-hostname`
        FROM leases l
        WHERE l.ip=s.ip OR LOWER(CONVERT(l.`hardware-ethernet` USING latin1)) COLLATE latin1_general_ci=LOWER(s.mac) COLLATE latin1_general_ci
        ORDER BY IF(l.ip=s.ip, 0, 1), l.active DESC, l.last_seen DESC
        LIMIT 1
      ), ''), '') AS name,
      '' AS vendor,
      COALESCE((
        SELECT i.important
        FROM ips i
        WHERE i.ip=s.ip OR LOWER(i.mac) COLLATE latin1_general_ci=LOWER(s.mac) COLLATE latin1_general_ci
        ORDER BY IF(i.ip=s.ip, 0, 1), i.id DESC
        LIMIT 1
      ), 0) AS important
    FROM stats s
    WHERE s.ip IS NOT NULL
      AND s.ip<>''
      AND s.date_begin >= DATE_SUB(NOW(), INTERVAL $hours HOUR)
      AND EXISTS (SELECT 1 FROM stats prev_exists WHERE prev_exists.ip=s.ip AND prev_exists.id<s.id)
    ORDER BY s.date_begin DESC, s.id DESC
  ");
  $stmt->execute();

  $changes = array();
  $statusCounts = array();
  $hosts = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = (string)($row["status"] ?? "");
    $ip = (string)($row["ip"] ?? "");
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    if ($ip !== "")
      $hosts[$ip] = true;

    $row["id"] = (int)$row["id"];
    $row["duration"] = (int)$row["duration"];
    $row["current"] = (int)$row["current"];
    $row["important"] = (int)$row["important"];
    $row["vendor"] = getVendor((string)($row["mac"] ?? ""));
    $row["previous_status"] = ($row["previous_status"] ?? "") === "" ? null : $row["previous_status"];
    $changes[] = $row;
  }

  $portChanges = get_port_notify($hours);
  $portChangeCounts = array();
  foreach ($portChanges as $change) {
    $type = (string)($change['change_type'] ?? '');
    $portChangeCounts[$type] = ($portChangeCounts[$type] ?? 0) + 1;
    $ip = (string)($change['ip'] ?? '');
    if ($ip !== '')
      $hosts[$ip] = true;
  }

  return array(
    "network" => $network,
    "since" => date("Y-m-d H:i:s", time() - $hours * 60 * 60),
    "hours" => $hours,
    "summary" => array(
      "total" => count($changes) + count($portChanges),
      "status_total" => count($changes),
      "port_total" => count($portChanges),
      "hosts" => count($hosts),
      "status_counts" => $statusCounts,
      "port_change_counts" => $portChangeCounts
    ),
    "changes" => $changes,
    "port_changes" => $portChanges
  );
}

function get_netboot_images() {
  $stmt = getDb()->prepare("
    SELECT id, name, filename, original_name, size, created_at,
      (SELECT COUNT(*) FROM ips WHERE ips.netboot_image_id=netboot_images.id) AS hosts
    FROM netboot_images
    ORDER BY created_at DESC, id DESC
  ");
  $stmt->execute();

  $images = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row["id"] = (int)$row["id"];
    $row["size"] = (int)$row["size"];
    $row["hosts"] = (int)$row["hosts"];
    $row["url"] = netboot_image_url($row["id"]);
    $images[] = $row;
  }
  return $images;
}

function create_netboot_image(array $file, string $name = "") {
  if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
    throw new RuntimeException(netboot_upload_error((int)($file["error"] ?? UPLOAD_ERR_NO_FILE)));

  $tmp = (string)($file["tmp_name"] ?? "");
  if ($tmp === "" || !is_uploaded_file($tmp))
    throw new RuntimeException("invalid upload");

  $original = basename((string)($file["name"] ?? "netboot.img"));
  validate_netboot_image($tmp, $original);
  ensure_netboot_dir();
  $displayName = trim($name) !== "" ? trim($name) : $original;
  $filename = unique_netboot_filename($original);
  $target = netboot_dir() . "/" . $filename;

  if (!move_uploaded_file($tmp, $target))
    throw new RuntimeException("failed to save upload");

  chmod($target, 0644);
  $stmt = getDb()->prepare("
    INSERT INTO netboot_images (name, filename, original_name, size)
    VALUES (:name, :filename, :original_name, :size)
  ");
  $stmt->execute(array(
    "name" => $displayName,
    "filename" => $filename,
    "original_name" => $original,
    "size" => (int)($file["size"] ?? filesize($target))
  ));

  return get_netboot_image((int)getDb()->lastInsertId());
}

function netboot_allowed_extensions(): array {
  return array('efi', 'kpxe', 'kkpxe', 'kkkpxe', 'pxe', 'lkrn', '0', 'ipxe');
}

function validate_netboot_image(string $path, string $original): void {
  $extension = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
  $allowed = netboot_allowed_extensions();
  if (!in_array($extension, $allowed, true)) {
    $suffix = $extension === '' ? '(none)' : '.' . $extension;
    throw new RuntimeException(
      "unsupported netboot file extension $suffix; allowed: ." . implode(', .', $allowed)
    );
  }

  if (preg_match('/\.(?:php[0-9]*|phtml|phar)(?:\.|$)/i', $original))
    throw new RuntimeException('executable PHP filenames are not allowed');

  $size = filesize($path);
  if ($size === false || $size < 1)
    throw new RuntimeException('netboot file is empty');

  $valid = false;
  if ($extension === 'efi') {
    $valid = netboot_is_efi($path);
  } elseif ($extension === 'ipxe') {
    $valid = netboot_is_ipxe_script($path);
  } elseif ($extension === '0') {
    $valid = netboot_contains_marker($path, array('PXELINUX'));
  } else {
    $valid = netboot_contains_marker($path, array('iPXE', 'PXELINUX'));
  }

  if (!$valid)
    throw new RuntimeException("file content does not match .$extension netboot format");
}

function netboot_is_efi(string $path): bool {
  $handle = fopen($path, 'rb');
  if ($handle === false)
    return false;

  try {
    $dosHeader = fread($handle, 64);
    if ($dosHeader === false || strlen($dosHeader) < 64 || substr($dosHeader, 0, 2) !== "MZ")
      return false;

    $offset = unpack('Voffset', substr($dosHeader, 60, 4));
    $peOffset = (int)($offset['offset'] ?? 0);
    $size = filesize($path);
    if ($peOffset < 64 || $size === false || $peOffset > $size - 96)
      return false;
    if (fseek($handle, $peOffset) !== 0)
      return false;

    $peHeader = fread($handle, 96);
    if ($peHeader === false || strlen($peHeader) < 94 || substr($peHeader, 0, 4) !== "PE\0\0")
      return false;

    $magicData = unpack('vmagic', substr($peHeader, 24, 2));
    $subsystemData = unpack('vsubsystem', substr($peHeader, 92, 2));
    $magic = (int)($magicData['magic'] ?? 0);
    $subsystem = (int)($subsystemData['subsystem'] ?? 0);
    return in_array($magic, array(0x10b, 0x20b), true) && $subsystem === 10;
  } finally {
    fclose($handle);
  }
}

function netboot_is_ipxe_script(string $path): bool {
  $prefix = netboot_read_prefix($path, 4096);
  if ($prefix === null || strpos($prefix, "\0") !== false)
    return false;

  if (str_starts_with($prefix, "\xEF\xBB\xBF"))
    $prefix = substr($prefix, 3);
  return preg_match('/^#!ipxe(?:[ \t]*\r?\n|[ \t]+)/i', $prefix) === 1;
}

function netboot_contains_marker(string $path, array $markers): bool {
  $prefix = netboot_read_prefix($path, 1024 * 1024);
  if ($prefix === null)
    return false;

  foreach ($markers as $marker) {
    if (strpos($prefix, $marker) !== false)
      return true;
  }
  return false;
}

function netboot_read_prefix(string $path, int $limit): ?string {
  $handle = fopen($path, 'rb');
  if ($handle === false)
    return null;

  try {
    $contents = fread($handle, $limit);
    return $contents === false ? null : $contents;
  } finally {
    fclose($handle);
  }
}

function delete_netboot_image(int $id): array {
  $image = get_netboot_image($id);
  if ($image === false)
    throw new OutOfBoundsException("netboot image not found");

  $stmt = getDb()->prepare("UPDATE ips SET netboot_image_id=NULL WHERE netboot_image_id=:id");
  $stmt->execute(array("id" => $id));

  $stmt = getDb()->prepare("DELETE FROM netboot_images WHERE id=:id");
  $stmt->execute(array("id" => $id));

  return $image;
}

function delete_netboot_image_file(array $image): void {
  $path = netboot_image_path($image);
  if (is_file($path))
    @unlink($path);
}

function get_netboot_image(int $id) {
  $stmt = getDb()->prepare("SELECT id, name, filename, original_name, size, created_at FROM netboot_images WHERE id=:id");
  $stmt->execute(array("id" => $id));
  $image = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($image === false)
    return false;
  $image["id"] = (int)$image["id"];
  $image["size"] = (int)$image["size"];
  $image["url"] = netboot_image_url($image["id"]);
  return $image;
}

function netboot_image_url(int $id): string {
  return '/api/netboot/images/' . $id . '/file';
}

function netboot_image_path(array $image): string {
  return netboot_dir() . '/' . basename((string)($image['filename'] ?? ''));
}

function netboot_image_exists($id): bool {
  if ($id === null)
    return true;
  return get_netboot_image((int)$id) !== false;
}

function netboot_dir(): string {
  return FENPING_DATA_DIR . "/netboot";
}

function ensure_netboot_dir(): void {
  $dir = netboot_dir();
  if (!is_dir($dir) && !mkdir($dir, 0755, true))
    throw new RuntimeException("failed to create netboot directory");
  if (!is_writable($dir))
    throw new RuntimeException("netboot directory is not writable");
}

function unique_netboot_filename(string $original): string {
  $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($original));
  $safe = trim($safe, '.-');
  if ($safe === '')
    $safe = 'image.bin';

  $prefix = date('YmdHis') . '-' . bin2hex(random_bytes(4));
  return $prefix . '-' . $safe;
}

function netboot_upload_error(int $code): string {
  $errors = array(
    UPLOAD_ERR_INI_SIZE => "upload is too large",
    UPLOAD_ERR_FORM_SIZE => "upload is too large",
    UPLOAD_ERR_PARTIAL => "upload was incomplete",
    UPLOAD_ERR_NO_FILE => "no file uploaded",
    UPLOAD_ERR_NO_TMP_DIR => "missing upload temp directory",
    UPLOAD_ERR_CANT_WRITE => "failed to write upload",
    UPLOAD_ERR_EXTENSION => "upload blocked by extension"
  );
  return $errors[$code] ?? "upload failed";
}

function get_history($ip, $blipSeconds = 120) {
  $stmt = getDb()->prepare("select *, UNIX_TIMESTAMP(date_begin) as `begin`, UNIX_TIMESTAMP(date_end) as `end`, UNIX_TIMESTAMP(date_end)-UNIX_TIMESTAMP(date_begin) as duration from stats where ip=:ip and date_end > date_sub(now(), interval 7 day) order by id asc");
  $stmt->execute(array("ip" => $ip));
  $before = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $cutoff = time() - 7 * 24 * 60 * 60;
  if (count($before) > 0) {
    $now = time();
    $lastIndex = count($before) - 1;
    $before[$lastIndex]["end"] = $now;
    $before[$lastIndex]["date_end"] = date("Y-m-d H:i:s", $now);
    $before[$lastIndex]["duration"] = max(0, $now - (int)$before[$lastIndex]["begin"]);
    $before[$lastIndex]["current"] = 1;
  }
  foreach ($before as &$row) {
    if ((int)$row["begin"] < $cutoff) {
      $row["begin"] = $cutoff;
      $row["date_begin"] = date("Y-m-d H:i:s", $cutoff);
    }
    $row["duration"] = max(0, (int)$row["end"] - (int)$row["begin"]);
  }
  unset($row);

  $after = array();
  $lastIndex = count($before) - 1;
  foreach ($before as $index => $i) {
    $rowIndex = count($after)-1;
    if ($rowIndex >= 0 && $after[$rowIndex]["status"] == $i["status"]) {
      $after[$rowIndex]["date_end"] = $i["date_end"];
      $after[$rowIndex]["end"] = $i["end"];
      $after[$rowIndex]["duration"] += $i["duration"];
      if (!empty($i["current"]))
        $after[$rowIndex]["current"] = 1;
    } else {
      array_push($after, $i);
    }
  }
  return merge_history_blips($after, $blipSeconds);
}

function merge_history_blips($rows, $blipSeconds) {
  $changed = true;
  while ($changed) {
    $changed = false;
    $count = count($rows);
    for ($i = 0; $i < $count; $i++) {
      if (!empty($rows[$i]["current"]) || (int)$rows[$i]["duration"] >= $blipSeconds)
        continue;

      if ($i > 0 && $i + 1 < $count && $rows[$i - 1]["status"] == $rows[$i + 1]["status"]) {
        $rows[$i - 1]["date_end"] = $rows[$i + 1]["date_end"];
        $rows[$i - 1]["end"] = $rows[$i + 1]["end"];
        $rows[$i - 1]["duration"] += $rows[$i]["duration"] + $rows[$i + 1]["duration"];
        if (!empty($rows[$i + 1]["current"]))
          $rows[$i - 1]["current"] = 1;
        array_splice($rows, $i, 2);
        $changed = true;
        break;
      }

      if ($i > 0) {
        $rows[$i - 1]["date_end"] = $rows[$i]["date_end"];
        $rows[$i - 1]["end"] = $rows[$i]["end"];
        $rows[$i - 1]["duration"] += $rows[$i]["duration"];
        array_splice($rows, $i, 1);
        $changed = true;
        break;
      }

      if ($i + 1 < $count) {
        $rows[$i + 1]["date_begin"] = $rows[$i]["date_begin"];
        $rows[$i + 1]["begin"] = $rows[$i]["begin"];
        $rows[$i + 1]["duration"] += $rows[$i]["duration"];
        array_splice($rows, $i, 1);
        $changed = true;
        break;
      }
    }
  }
  return array_values($rows);
}

function get_history_summary($rows) {
  $observed = 0;
  $up = 0;
  $longestDown = 0;

  foreach ($rows as $row) {
    $duration = max(0, (int)($row["duration"] ?? 0));
    $observed += $duration;
    if (($row["status"] ?? "") == "Up")
      $up += $duration;
    else
      $longestDown = max($longestDown, $duration);
  }

  $transitions = max(0, count($rows) - 1);
  $uptime = $observed > 0 ? round(($up / $observed) * 100, 1) : 0;
  $current = count($rows) > 0 ? $rows[count($rows) - 1] : null;
  $stable = count($rows) <= 1 && $current !== null && ($current["status"] ?? "") == "Up" && $uptime >= 99.95;

  return array(
    "uptime_percent" => $uptime,
    "observed_seconds" => $observed,
    "up_seconds" => $up,
    "transitions" => $transitions,
    "longest_down_seconds" => $longestDown,
    "current_status" => $current["status"] ?? "",
    "current_seconds" => $current === null ? 0 : max(0, (int)($current["duration"] ?? 0)),
    "stable" => $stable,
    "level" => stability_level($uptime, $transitions, $longestDown, $current["status"] ?? ""),
    "label" => stability_label($uptime)
  );
}

function stability_label($uptime) {
  return (int)round($uptime) . "%";
}

function stability_level($uptime, $transitions, $longestDown, $currentStatus) {
  if ($currentStatus != "Up")
    return "bad";
  if ($uptime >= 99 && $transitions <= 1)
    return "good";
  if ($uptime >= 95 && $transitions <= 4 && $longestDown < 60 * 60)
    return "warn";
  return "bad";
}
