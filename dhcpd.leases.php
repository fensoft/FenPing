<?php

include "config.php";

$contents=file_get_contents("/var/lib/dhcp/dhcpd.leases");
$contents=explode("\n", $contents);

$from = array("binding state", "hardware ethernet", "set vendor-class-identifier =");
$to   = array("binding-state", "hardware-ethernet", "vendor-class-identifier");

$current=0;
foreach($contents as $line) {
  $line = str_replace($from, $to, $line);
  if (strstr($line, "binding") != FALSE)
    continue;
  switch($current) {
    case 0:
      if(preg_match("/^\s*(|#.*)$/", $line, $m)) {
      }
      else if(preg_match("/^lease (.*) {/", $line, $m)) {
        $current=$m[1];
      }
      else if(preg_match("/^server-duid/", $line)) {
        // ignore
      }
      else {
        print "Failed parsing '$line'\n";
      }
      break;
    default:
      if(preg_match("/^\s*([a-z\-]+) (.*);$/", $line, $m)) {
        if (in_array($m[1], array("starts", "ends", "tstp", "cltt")))
          $m[2] = substr($m[2], 2);
        if (!in_array($m[1], array("uid")))
          $data[$current][$m[1]] = trim($m[2], '"');
      }
      elseif(preg_match("/}/", $line, $m)) {
        $current=0;
      }
      else {
        print "Failed parsing '$line'\n";
      }
  }
}

$db = new PDO('mysql:host=' . $db_host . ';dbname=' . $db_name, $db_user, $db_pass);
$stmt = $db->prepare("truncate leases");
$stmt->execute();
foreach ($data as $ip => $content) {
  $query = "INSERT INTO leases (`ip`";
  foreach ($content as $key => $val)
    $query = $query . ",`" . $key . '`';
  $query = $query . ") VALUES ('" . $ip . "'";
  foreach ($content as $key => $val)
    $query = $query . ",'" . $val . "'";
  $query = $query . ")";
  $stmt = $db->prepare($query);
  $stmt->execute();
}
