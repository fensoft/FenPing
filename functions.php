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
  global $db_host;
  global $db_name;
  global $db_user;
  global $db_pass;
  return new PDO('mysql:host=' . $db_host . ';dbname=' . $db_name, $db_user, $db_pass);;
}

function getInventory() {
  global $myself;

  if (!file_exists(__DIR__ . '/nmap'))
    mkdir(__DIR__ . '/nmap');
  if (!file_exists(__DIR__ . '/res/xsl'))
    mkdir(__DIR__ . '/res/xsl');

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
    $num = str_replace($network . ".", "", $ip);

    file_put_contents("res/xsl/nmap.xsl", file_get_contents("/usr/share/nmap/nmap.xsl"));
    if (file_exists(__DIR__ . "/nmap.raw/" . $num . ".xml")) {
      $data["xml"] = $num;
      $content = str_replace("file:///usr/bin/../share/nmap/", "../res/xsl/", file_get_contents(__DIR__ . "/nmap.raw/" . $num . ".xml"));
      file_put_contents("nmap/" . $num . ".xml", $content);
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

function create($ip, $mac) {
  $stmt = getDb()->prepare("INSERT INTO ips (mac,ip) VALUES (:mac,:ip)");
  $stmt->execute(array("mac" => $mac, "ip" => $ip));
}

function edit($id, $ip, $mac, $name, $repeater, $important) {
  $stmt = getDb()->prepare("UPDATE ips SET name=:name, mac=:mac, ip=:ip, repeater=:repeater, important=:important WHERE id=:id");
  $stmt->execute(array("name" => $name, "mac" => $mac, "ip" => $ip, "repeater" => $repeater != "1" ? null : "1", "important" => $important != "1" ? null : "1", "id" => $id));
}

function del($id) {
  $stmt = getDb()->prepare("DELETE FROM ips WHERE id=:id");
  $stmt->execute(array("id" => $id));
}