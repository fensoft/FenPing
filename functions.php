<?php
require_once __DIR__ . '/config.php';

function getVendor($mac) {
  $db = getDb();
  $stmt = $db->prepare("select * from vendors where mac=:mac");
  $stmt->execute(array("mac" => $mac));
  $data = $stmt->fetch();
  if ($data == false) {
    $url = "https://api.macvendors.com/" . urlencode($mac);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $res = curl_exec($ch);
    if ($res === false)
      $res = "";
    if (strpos($res, "Too Many Requests") !== false)
      $res = "";
    if (strpos($res, "Page not found") !== false)
      $res = "Unknown";
    if (strpos($res, "Not Found") !== false)
      $res = "Unknown";
    sleep(1);
    $stmt = $db->prepare("INSERT INTO vendors (mac, vendors) VALUES (:mac, :vendor)");
    if ($res != "")
      $stmt->execute(array("mac" => $mac, "vendor" => $res));
    return $res;
  } else {
    return $data[1];
  }
}

function getDb() {
  global $db;
  global $db_host;
  global $db_name;
  global $db_user;
  global $db_pass;
  if (!isset($db))
    $db = new PDO('mysql:host=' . $db_host . ';dbname=' . $db_name, $db_user, $db_pass);
  return $db;
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
  $stmt = $db->prepare("select NULL as id, `client-hostname` as name, ip, `hardware-ethernet` as mac, 'Down' as status, starts as date, 0 as important from leases where convert(ends, datetime) > date_sub(now(), interval 7 day)");
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
    if ($ip != "" && file_exists(__DIR__ . "/nmap/" . $ip . ".xml")) {
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
    SELECT s.id, s.ip, s.mode, s.state, s.status, s.date_begin, s.date_end, s.duration, s.ports_count, s.xml, s.error
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
    $scans[$row["ip"]] = $row;
  }
  return $scans;
}

function addCategory($ip, $name) {
  $stmt = getDb()->prepare("INSERT INTO `range` (ip_begin,`type`) VALUES (:ip, :name)");
  $stmt->execute(array("ip" => normalizeCategoryIp($ip), "name" => $name));
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
  $mac = strtolower(str_replace("-", ":", (string)$mac));
  $stmt = getDb()->prepare("INSERT INTO ips (mac,ip) VALUES (:mac,:ip)");
  $stmt->execute(array("mac" => $mac, "ip" => $ip));
  return getDb()->lastInsertId();
}

function edit($id, $ip, $mac, $name, $repeater, $important, $web, $router, $dns) {
  $mac = strtolower(str_replace("-", ":", (string)$mac));
  $stmt = getDb()->prepare("UPDATE ips SET name=:name, mac=:mac, ip=:ip, repeater=:repeater, important=:important, web=:web, router=:router, dns=:dns WHERE id=:id");
  if (!$stmt->execute(array("name" => $name, "mac" => $mac, "ip" => $ip, "repeater" => $repeater != "1" ? null : "1", "important" => $important != "1" ? null : "1", "web" => $web != "1" ? null : "1", "router" => $router == "" ? null : $router, "dns" => $dns == "" ? null : $dns, "id" => $id)))
    print_r($stmt->errorInfo());
}

function del($id) {
  $stmt = getDb()->prepare("DELETE FROM ips WHERE id=:id");
  $stmt->execute(array("id" => $id));
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

function get_notify($hours = 24) {
  global $network;
  $hours = max(1, min(168, (int)$hours));
  $stmt = getDb()->prepare("
    SELECT
      s.id,
      s.ip,
      s.mac,
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
      COALESCE((
        SELECT i.name
        FROM ips i
        WHERE i.ip=s.ip OR LOWER(i.mac) COLLATE latin1_general_ci=LOWER(s.mac) COLLATE latin1_general_ci
        ORDER BY IF(i.ip=s.ip, 0, 1), i.id DESC
        LIMIT 1
      ), '') AS name,
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
    $row["previous_status"] = ($row["previous_status"] ?? "") === "" ? null : $row["previous_status"];
    $changes[] = $row;
  }

  return array(
    "network" => $network,
    "since" => date("Y-m-d H:i:s", time() - $hours * 60 * 60),
    "hours" => $hours,
    "summary" => array(
      "total" => count($changes),
      "hosts" => count($hosts),
      "status_counts" => $statusCounts
    ),
    "changes" => $changes
  );
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
