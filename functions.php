<?php
require __DIR__ . '/config.php';

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
    if (strpos($res, "Too Many Requests"))
      $res = "";
    if (strpos($res, "Page not found"))
      $res = "Unknown";
    if (strpos($res, "Not Found"))
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

function getInventory() {
  global $myself;

  $db = getDb();

  $repeater = array();
  $stmt = $db->prepare("select mac, ip, name from ips where repeater=1");
  $stmt->execute();
  while ($data = $stmt->fetch()) {
    $repeater[strtolower($data[0])] = array("ip" => $data[1], "name" => $data[2]);
  }

  $ips = array();
  #cas normal
  $stmt = $db->prepare("select id, name, i.ip, i.mac, status, date, i.important, i.web from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and i.ip=p.ip and (p.mac=i.mac or p.mac='')");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC))
    $ips[$data["ip"]] = $data;
  #derri�re un autre routeur
  $stmt = $db->prepare("select id, name, i.ip, p.mac, i.mac as mac_i, status, date, i.important, i.web from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and p.mac!='' and i.ip=p.ip and p.mac!=i.mac");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($repeater[$data["mac"]]))
      $data["via"] = "via " . $repeater[$data["mac"]]["name"];
    $data["mac"] = $data["mac_i"];
    $ips[$data["ip"]] = $data;
  }
  #mauvaise ip
  $stmt = $db->prepare("select id, name, p.ip, i.ip as ip_should, p.mac, status, date, i.important, i.web from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.ip != i.ip and status = 'Up' and repeater is null");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $ips[$data["ip"]] = $data;
  }
  #en db mais ne pingent pas
  $stmt = $db->prepare("select id, name, p.ip, p.mac, status, date, i.important, i.web from ips i right outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.status != 'Down' and id is null");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $ips[$data["ip"]] = $data;
  }
  #dans les leases avec des new mac
  $macs = array();
  foreach ($ips as $key => $data) {
    array_push($macs, strtoupper($data["mac"]));
  }
  $stmt = $db->prepare("select NULL as id, `client-hostname` as name, ip, `hardware-ethernet` as mac, 'Down' as status, starts as date, 0 as important from leases where convert(ends, datetime) > date_sub(now(), interval 7 day)");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!in_array(strtoupper($data["mac"]), $macs)) {
      if (!isset($ips[$data["ip"]]))
        $ips[$data["ip"]] = $data;
    }
    if (isset($ips[$data["ip"]]) && $ips[$data["ip"]]["name"] == "" && $data["name"] != "")
      $ips[$data["ip"]]["name"] = $data["name"];
  }
  #toujours up
  $ips[$myself]["status"] = "Up";
  $sorted_ips = array();
  foreach ($ips as $key => $data) {
    $sorted_ips[ip2long($key)] = $data;
  }
  ksort($sorted_ips);
  global $network;
  $old_ip = $network . ".0";
  $res = array();
  $stats = get_stats();
  foreach ($sorted_ips as $key => $data) {
    $ip = $data["ip"];
    $stmt2 = $db->prepare("select ip_begin, type from `range` where INET_ATON(:ip_begin) < INET_ATON(ip_begin) and INET_ATON(:ip_end) >= INET_ATON(ip_begin) order by INET_ATON(ip_begin) desc");
    $stmt2->execute(array("ip_begin" => $old_ip, "ip_end" => $ip));
    $data2 = $stmt2->fetch();
    if ($data2 != false) {
      $old_ip = $ip;
      $data["category"] = $data2["type"];
      $data["category_ip"] = $data2["ip_begin"];
    }
    $data["vendor"] = "";
    if ($data["mac"] != "")
      $data["vendor"] = getVendor($data["mac"]);
    if (isset($stats[$ip])) {
      $data["stats"] = $stats[$ip];
      $data["stats2"] = count(get_history($data["ip"]));
    }
    if (file_exists(__DIR__ . "/nmap/" . $ip . ".xml")) {
      $data["xml"] = $ip;
    }
    array_push($res, $data);
  }
  return $res;
}

function addCategory($ip, $name) {
  global $network;
  $stmt = getDb()->prepare("INSERT INTO `range` (ip_begin,`type`) VALUES (:ip, :name)");
  $stmt->execute(array("ip" => $ip, "name" => $name));
}

function delCategory($category) {
  $stmt = getDb()->prepare("DELETE FROM `range` WHERE ip_begin=:ip");
  $stmt->execute(array("ip" => $category));
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
  $mac = strtolower(str_replace("-", ":", $mac));
  $stmt = getDb()->prepare("INSERT INTO ips (mac,ip) VALUES (:mac,:ip)");
  $stmt->execute(array("mac" => $mac, "ip" => $ip));
  return getDb()->lastInsertId();
}

function edit($id, $ip, $mac, $name, $repeater, $important, $web, $router, $dns) {
  $mac = strtolower(str_replace("-", ":", $mac));
  $stmt = getDb()->prepare("UPDATE ips SET name=:name, mac=:mac, ip=:ip, repeater=:repeater, important=:important, web=:web, router=:router, dns=:dns WHERE id=:id");
  if (!$stmt->execute(array("name" => $name, "mac" => $mac, "ip" => $ip, "repeater" => $repeater != "1" ? null : "1", "important" => $important != "1" ? null : "1", "web" => $web != "1" ? null : "1", "router" => $router == "" ? null : $router, "dns" => $dns == "" ? null : $dns, "id" => $id)))
    print_r($stmt->errorInfo());
}

function del($id) {
  $stmt = getDb()->prepare("DELETE FROM ips WHERE id=:id");
  $stmt->execute(array("id" => $id));
}

function get_stats() {
  $stmt = getDb()->prepare("select ip, count(ip) as cnt from stats where date_begin > date_sub(now(), interval 1 day) and nb_scan > 10 group by ip");
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
  return intval($val / (60 * 60 * 24)) . " j";
}

function get_history($ip, $regroup = 10) {
  $stmt = getDb()->prepare("select *, UNIX_TIMESTAMP(date_begin) as `begin`, UNIX_TIMESTAMP(date_end) as `end`, UNIX_TIMESTAMP(date_end)-UNIX_TIMESTAMP(date_begin) as duration from stats where ip=:ip order by id asc limit 1000");
  $stmt->execute(array("ip" => $ip));
  $before = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $after = array();
  foreach ($before as $i) {
    if ($i["nb_scan"] >= $regroup) {
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