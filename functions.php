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
      $historyCount = count(get_history($ip));
      $data["stats"] = $historyCount;
      $data["stats2"] = $historyCount;
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
  $stmt = getDb()->prepare("
    select s.ip, count(s.ip) as cnt
    from stats s
    inner join (
      select ip, max(id) as latest_id
      from stats
      group by ip
    ) latest on latest.ip=s.ip
    where s.date_end > date_sub(now(), interval 7 day)
      and (s.nb_scan > 10 or s.id=latest.latest_id)
    group by s.ip
    having cnt > 1
  ");
  $stmt->execute();
  $arr = array();
  while ($i = $stmt->fetch(PDO::FETCH_ASSOC))
    $arr[$i["ip"]] = $i["cnt"];
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

function get_history($ip, $regroup = 10) {
  $stmt = getDb()->prepare("select *, UNIX_TIMESTAMP(date_begin) as `begin`, UNIX_TIMESTAMP(date_end) as `end`, UNIX_TIMESTAMP(date_end)-UNIX_TIMESTAMP(date_begin) as duration from stats where ip=:ip and date_end > date_sub(now(), interval 7 day) order by id asc");
  $stmt->execute(array("ip" => $ip));
  $before = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (count($before) > 0) {
    $now = time();
    $lastIndex = count($before) - 1;
    $before[$lastIndex]["end"] = $now;
    $before[$lastIndex]["date_end"] = date("Y-m-d H:i:s", $now);
    $before[$lastIndex]["duration"] = max(0, $now - (int)$before[$lastIndex]["begin"]);
    $before[$lastIndex]["current"] = 1;
  }
  $after = array();
  $lastIndex = count($before) - 1;
  foreach ($before as $index => $i) {
    if ($i["nb_scan"] >= $regroup || $index === $lastIndex) {
      $index = count($after)-1;
      if ($index >= 0 && $after[$index]["status"] == $i["status"]) {
        $after[$index]["date_end"] = $i["date_end"];
        $after[$index]["end"] = $i["end"];
        $after[$index]["duration"] += $i["duration"];
      } else {
        array_push($after, $i);
      }
    }
  }
  return $after;
}
