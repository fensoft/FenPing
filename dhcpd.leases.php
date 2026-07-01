<?php

include "config.php";

$leaseFile = "/var/lib/dhcp/dhcpd.leases";
$contents = is_readable($leaseFile) ? file_get_contents($leaseFile) : "";
if ($contents === false)
  $contents = "";
$contents = explode("\n", $contents);

$from = array("binding state", "hardware ethernet", "set vendor-class-identifier =");
$to   = array("binding-state", "hardware-ethernet", "vendor-class-identifier");
$allowedColumns = array("starts", "ends", "tstp", "cltt", "hardware-ethernet", "client-hostname", "vendor-class-identifier");

$current=0;
$data = array();
foreach($contents as $line) {
  $line = str_replace($from, $to, $line);
  if(preg_match("/^\s*(|#.*)$/", $line))
    continue;
  if (strstr($line, "binding") != FALSE)
    continue;
  switch($current) {
    case 0:
      if(preg_match("/^lease (.*) {/", $line, $m)) {
        $current=$m[1];
      }
      else if(preg_match("/^server-duid/", $line)) {
        // ignore
      }
      else if(preg_match("/^authoring-byte-order/", $line)) {
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
        if (in_array($m[1], $allowedColumns))
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
  $columns = array("ip");
  $params = array(":ip");
  $values = array("ip" => $ip);
  foreach ($content as $key => $val) {
    if (!in_array($key, $allowedColumns))
      continue;
    array_push($columns, $key);
    array_push($params, ":" . str_replace("-", "_", $key));
    $values[str_replace("-", "_", $key)] = $val;
  }
  $query = "INSERT INTO leases (`" . implode("`,`", $columns) . "`) VALUES (" . implode(",", $params) . ")";
  $stmt = $db->prepare($query);
  $stmt->execute($values);
}
