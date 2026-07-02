<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';

function smarty_modifier_temps($in) { return temps(intval($in)); }

$smarty = new Smarty();
$smarty->registerPlugin("modifier", "strtolower", function($value) {
  return strtolower((string)$value);
});
$smarty->registerPlugin("modifier", "temps", "smarty_modifier_temps");
$smarty->assign("network", $network);

function testPassword() {
  global $smarty;
  global $password;
  if (($_REQUEST["p"] ?? "") != $password) {
    $smarty->display("templates/wrongpassword.tpl");
    die();
  }
}

function refreshPingInBackground() {
  exec('flock -n /tmp/ping.lck -c "/usr/bin/sudo /usr/bin/php ' . escapeshellarg(__DIR__ . '/cli.php') . ' ping" > /dev/null 2>&1 &');
}

if (isset($_REQUEST["addcat"])) {
  $smarty->assign("name", "");
  $smarty->display("templates/addcat.tpl");
  die();
} else if (isset($_REQUEST["addcat2"])) {
  testPassword();
  addCategory($_REQUEST["ip"] ?? "", $_REQUEST["name"] ?? "");
  $smarty->display("templates/catadded.tpl");
  die();
} else if (isset($_REQUEST["delcat"])) {
  $smarty->assign("ip", $_REQUEST["delcat"]);
  $smarty->display("templates/deletecat.tpl");
  die();
} else if (isset($_REQUEST["delcat2"])) {
  testPassword();
  delCategory($_REQUEST["delcat2"]);
  $smarty->display("templates/catdeleted.tpl");
  die();
} else if (isset($_REQUEST["create"])) {
  $id = create(NULL, $_REQUEST["create"]);
  $smarty->assign("edit", $id);
  $smarty->display("templates/create.tpl");
  die();
} else if (isset($_REQUEST["edit"])) {
  $data = getId($_REQUEST["edit"]);
  if ($data == false) {
    echo("not found");
    die();
  }
  $ip = $data["ip"] ?? "";
  $smarty->assign("edit", $_REQUEST["edit"]);
  $smarty->assign("ip", $ip);
  $smarty->assign("mac", $data["mac"] ?? "");
  $smarty->assign("name", $data["name"] ?? "");
  $smarty->assign("important", $data["important"] ?? "");
  $smarty->assign("repeater", $data["repeater"] ?? "");
  $smarty->assign("router", $data["router"] ?? "");
  $smarty->assign("dns", $data["dns"] ?? "");
  $smarty->assign("web", $data["web"] ?? "");
  $smarty->assign("num", str_replace($network . ".", "", $ip));
  $smarty->display("templates/edit.tpl");
  die();
} else if (isset($_REQUEST["edit2"])) {
  testPassword();
  if ($_REQUEST["ip"] == "")
    $_REQUEST["ip"] = null;
  else
    $_REQUEST["ip"] = $network . "." . $_REQUEST["ip"];
  if ($_REQUEST["router"] == "")
    $_REQUEST["router"] = null;
  if ($_REQUEST["dns"] == "")
    $_REQUEST["dns"] = null;
  edit($_REQUEST["edit2"], $_REQUEST["ip"], $_REQUEST["mac"], $_REQUEST["name"], isset($_REQUEST["repeater"])?"1":null, isset($_REQUEST["important"])?"1":null, isset($_REQUEST["web"])?"1":null, $_REQUEST["router"], $_REQUEST["dns"]);
  $smarty->assign("log", exec("sudo " . __DIR__ . "/ips2hosts.sh"));
  $smarty->display("templates/edited.tpl");
  die();
} else if (isset($_REQUEST["del"])) {
  $smarty->assign("del", $_REQUEST["del"]);
  $smarty->display("templates/delete.tpl");
  die();
} else if (isset($_REQUEST["del2"])) {
  testPassword();
  del($_REQUEST["del2"]);
  $smarty->display("templates/deleted.tpl");
  die();
} else if (isset($_REQUEST["history"])) {
  $smarty->assign("history", get_history($_REQUEST["history"]));
  $smarty->display("templates/history.tpl");
  die();
}
refreshPingInBackground();
$res = getInventory();
$smarty->assign("results", $res);
$smarty->display("templates/header.tpl");
$smarty->display("templates/inventory.tpl");
$smarty->display("templates/footer.tpl");
