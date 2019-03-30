<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';

use \Firebase\JWT\JWT;

function checkAdmin() {
  global $secret;
  if (isset($_SERVER["HTTP_AUTHORIZATION"])) {
    try {
      $res = JWT::decode($_SERVER["HTTP_AUTHORIZATION"], $secret, array('HS256'));
      if ($res->admin == "1")
        return;
    } catch(Exception $e){
    }
  }
  //header('HTTP/1.0 403 Forbidden');
  //die();
}

function route($type, $name, $function) {
  if ($_SERVER["REQUEST_METHOD"] == $type && $_REQUEST["method"] == $name) {
    header('Content-Type: application/json');
    echo json_encode(call_user_func($function));
  }
}

route('GET', 'ips', function() {
  return getInventory();
});

route('GET', 'hash', function() {
  return password_hash($_REQUEST['hash'], PASSWORD_DEFAULT);
});

route('POST', 'login', function() {
  global $secret;
  $stmt = getDb()->prepare('select * from users where login=:login');
  $stmt->execute(array("login" => $_REQUEST['user']));
  $data = $stmt->fetch();
  if ($data && password_verify($_REQUEST['pass'], $data['pass']))
    return JWT::encode(array('admin' => true, 'user' => $_REQUEST['user']), $secret, 'HS256');
});

route('POST', 'restore', function() {
  checkAdmin();
  $stmt = getDb()->prepare(file_get_contents('dump.sql'));
  $stmt->execute();
  return "ok";
});

route('DELETE', 'ip', function() {
  checkAdmin();
  del($_REQUEST["id"]);
  return "ok";
});

route('POST', 'category', function() {
  checkAdmin();
  addCategory($_REQUEST["ip"], $_REQUEST["name"]);
  return "ok";
});

route('DELETE', 'category', function() {
  checkAdmin();
  delCategory($_REQUEST["ip"]);
  return "ok";
});

route('GET', 'ip', function() {
  checkAdmin();
  return getIp($_REQUEST["ip"]);
});

route('GET', 'mac', function() {
  checkAdmin();
  return getMac($_REQUEST["mac"]);
});

route('POST', 'ip', function() {
  checkAdmin();
  create($_REQUEST["ip"], $_REQUEST["mac"]);
  return "ok";
});

route('PUT', 'ip', function() {
  checkAdmin();
  edit($_REQUEST["id"], $_REQUEST["ip"], $_REQUEST["mac"], $_REQUEST["name"], $_REQUEST["repeater"], $_REQUEST["important"]);
  return "ok";
});
