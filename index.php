<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

$smarty = new Smarty();
$smarty->assign("network", $network);

$db = new PDO('mysql:host=' . $db_host . ';dbname=' . $db, $db_user, $db_pass);
if (!file_exists(__DIR__ . '/nmap'))
  mkdir(__DIR__ . '/nmap');
if (!file_exists(__DIR__ . '/res/xsl'))
  mkdir(__DIR__ . '/res/xsl');
  
function getVendor($mac) {
  global $db;
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
    sleep(1);
    $stmt = $db->prepare("INSERT INTO vendors (mac, vendors) VALUES (:mac, :vendor)");
    if ($res != "")
      $stmt->execute(array("mac" => $mac, "vendor" => $res));
    return $res;
  } else {
    return $data[1];
  }
}

function testPassword() {
  global $smarty;
  global $password;
  if ($_REQUEST["p"] != $password) {
    $smarty->display("templates/wrongpassword.tpl");
    die();
  }
}

if (isset($_REQUEST["addcat"])) {
  $smarty->display("templates/addcat.tpl");
  die();
} else if (isset($_REQUEST["addcat2"])) {
  testPassword();
  global $network;
  $stmt = $db->prepare("INSERT INTO `range` (ip_begin,`type`) VALUES (:ip, :name)");
  $stmt->execute(array("ip" => $network . "." . $_REQUEST["ip"], "name" => $_REQUEST["name"]));
  $smarty->display("templates/catadded.tpl");
  die();
} else if (isset($_REQUEST["delcat"])) {
  $smarty->assign("ip", $_REQUEST["delcat"]);
  $smarty->display("templates/deletecat.tpl");
  die();
} else if (isset($_REQUEST["delcat2"])) {
  testPassword();
  $stmt = $db->prepare("DELETE FROM `range` WHERE ip_begin=:ip");
  $stmt->execute(array("ip" => $_REQUEST["delcat2"]));
  $smarty->display("templates/catdeleted.tpl");
  die();
} else if (isset($_REQUEST["edit"])) {
  $stmt = $db->prepare("select * from ips where ip=:ip");
  $stmt->execute(array("ip" => $_REQUEST["edit"]));
  $data = $stmt->fetch();
  if ($data == false) {
    $stmt = $db->prepare("select * from ips where mac=:mac");
    $stmt->execute(array("mac" => $_REQUEST["mac"]));
    $data = $stmt->fetch();
    if ($data == false) {
      $stmt = $db->prepare("INSERT INTO ips (mac,ip) VALUES (:mac,:ip)");
      $stmt->execute(array("mac" => $_REQUEST["mac"], "ip" => $_REQUEST["edit"]));
      $smarty->assign("edit", $_REQUEST["edit"]);
      $smarty->display("templates/create.tpl");
      die();
    }
  }
  $smarty->assign("edit", $_REQUEST["edit"]);
  $smarty->assign("edit2", $data["id"]);
  $smarty->assign("ip", $data["ip"]);
  $smarty->assign("mac", $data["mac"]);
  $smarty->assign("name", $data["name"]);
  $smarty->assign("important", $data["important"]);
  $smarty->assign("repeater", $data["repeater"]);
  $smarty->assign("id", $_REQUEST["id"]);
  $smarty->assign("num", str_replace($network . ".", "", $data["ip"]));
  $smarty->display("templates/edit.tpl");
  die();
} else if (isset($_REQUEST["edit2"])) {
  testPassword();
  $stmt = $db->prepare("UPDATE ips SET name=:name, mac=:mac, ip=:ip, repeater=:repeater, important=:important WHERE id=:id");
  if ($_REQUEST["ip"] == "")
    $_REQUEST["ip"] = null;
  else
    $_REQUEST["ip"] = $network . "." . $_REQUEST["ip"];
  $stmt->execute(array("name" => $_REQUEST["name"], "mac" => $_REQUEST["mac"], "ip" => $_REQUEST["ip"], "repeater" => isset($_REQUEST["repeater"])?"1":null, "important" => isset($_REQUEST["important"])?"1":null, "id" => $_REQUEST["edit2"]));
  $smarty->assign("log", exec("sudo " . __DIR__ . "/ips2hosts.sh"));
  $smarty->display("templates/edited.tpl");
  die();
} else if (isset($_REQUEST["del"])) {
  $smarty->assign("del", $_REQUEST["del"]);
  $smarty->display("templates/delete.tpl");
  die();
} else if (isset($_REQUEST["del2"])) {
  testPassword();
  $stmt = $db->prepare("DELETE FROM ips WHERE id=:id");
  $stmt->execute(array("id" => $_REQUEST["del2"]));
  $smarty->display("templates/deleted.tpl");
  die();
}

$repeater = array();
$stmt = $db->prepare("select mac, ip, name from ips where repeater=1");
$stmt->execute();
while ($data = $stmt->fetch()) {
  $repeater[strtolower($data[0])] = array("ip" => $data[1], "name" => $data[2]);
}

$ips = array();
#cas normal
$stmt = $db->prepare("select id, name, i.ip, i.mac, status, date, i.important from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and i.ip=p.ip and (p.mac=i.mac or p.mac='')");
$stmt->execute();
while ($data = $stmt->fetch(PDO::FETCH_ASSOC))
  $ips[$data["ip"]] = $data;
#derrière un autre routeur
$stmt = $db->prepare("select id, name, i.ip, p.mac, i.mac as mac_i, status, date, i.important from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and p.mac!='' and i.ip=p.ip and p.mac!=i.mac");
$stmt->execute();
while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
  if (isset($repeater[$data["mac"]]))
    $data["via"] = "via " . $repeater[$data["mac"]]["name"];
  $data["mac"] = $data["mac_i"];
  $ips[$data["ip"]] = $data;
}
#mauvaise ip
$stmt = $db->prepare("select id, name, p.ip, i.ip as ip_should, p.mac, status, date, i.important from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.ip != i.ip and status = 'Up' and repeater is null");
$stmt->execute();
while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $ips[$data["ip"]] = $data;
}
#en db mais ne pingent pas
$stmt = $db->prepare("select id, name, p.ip, p.mac, status, date, i.important from ips i right outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.status != 'Down' and id is null");
$stmt->execute();
while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $ips[$data["ip"]] = $data;
}
$ips[$myself]["status"] = "Up";
$sorted_ips = array();
foreach ($ips as $key => $data) {
  $sorted_ips[ip2long($key)] = $data;
}
ksort($sorted_ips);
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
    $content = str_replace("file:///usr/bin/../share/nmap/", "../", file_get_contents(__DIR__ . "/nmap.raw/" . $num . ".xml"));
    file_put_contents("nmap/" . $num . ".xml", $content);
  }
  array_push($res, $data);
}
echo "<!--";
print_r($res);
echo "-->";
$smarty->assign("results", $res);
$smarty->display("templates/header.tpl");
$smarty->display("templates/inventory.tpl");