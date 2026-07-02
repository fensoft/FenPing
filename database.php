<?php

function db() {
  global $db_host, $db_user, $db_pass, $db_name;
  static $db = null;

  if ($db === null) {
    $db = new PDO('mysql:host=' . $db_host . ';dbname=' . $db_name, $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  return $db;
}

function pingStatements() {
  static $statements = null;

  if ($statements === null) {
    $db = db();
    $statements = array(
      "upsert" => $db->prepare("
        INSERT INTO ping (ip, mac, status) VALUES (:ip, NULLIF(:mac, ''), :status)
        ON DUPLICATE KEY UPDATE
          mac=IF(VALUES(mac) IS NULL, mac, VALUES(mac)),
          status=VALUES(status)
      "),
      "updateStatus" => $db->prepare("CALL update_status(:ip, :mac, :status)")
    );
  }

  return $statements;
}
