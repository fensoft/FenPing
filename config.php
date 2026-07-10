<?php
if (!function_exists('fenping_env')) {
  function fenping_env($name, $default = '') {
    $value = getenv($name);
    if ($value === false || $value === '')
      return $default;
    return $value;
  }
}

$db_host = fenping_env('DB_HOST', 'localhost');
$db_port = fenping_env('DB_PORT', '3306');
$db_user = fenping_env('DB_USER', 'root');
$db_pass = fenping_env('DB_PASS', 'root');
$db_name = fenping_env('DB_NAME', 'ping');
$network = fenping_env('NETWORK', '192.168.0');
$interface = fenping_env('IFACE', fenping_env('INTERFACE', fenping_env('HOST_INTERFACE', 'eth0')));
$myself = fenping_env('IP', '192.168.0.100');
$password = fenping_env('PASSWORD', '');
$secret = fenping_env('SECRET', 'token');
$discord_webhook_url = fenping_env('DISCORD_WEBHOOK_URL', '');
$fenping_data_dir = rtrim(fenping_env('FENPING_DATA_DIR', '/var/lib/fenping'), '/');
if ($fenping_data_dir === '')
  $fenping_data_dir = '/var/lib/fenping';
if (!defined('FENPING_DATA_DIR'))
  define('FENPING_DATA_DIR', $fenping_data_dir);
