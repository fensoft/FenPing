<?php

const DNSMASQ_CONF = '/etc/dnsmasq.d/fenping.conf';
const DNSMASQ_DHCP_HOSTS = '/etc/dnsmasq.d/fenping.dhcp-hosts';
const DNSMASQ_DHCP_OPTS = '/etc/dnsmasq.d/fenping.dhcp-opts';
const DNSMASQ_HOSTS = '/etc/dnsmasq.d/fenping.hosts';
const DNSMASQ_PID = '/var/run/dnsmasq.pid';
const DNSMASQ_RELOAD_SIGNAL = 1;

function runHostsCommand(): int {
  try {
    ensureDnsmasqDirs();

    $files = buildDnsmasqFiles();
    writeGeneratedFile(DNSMASQ_DHCP_HOSTS, $files['dhcpHosts']);
    writeGeneratedFile(DNSMASQ_DHCP_OPTS, $files['dhcpOptions']);
    writeGeneratedFile(DNSMASQ_HOSTS, $files['dnsHosts']);

    echo "dnsmasq files written" . PHP_EOL;
    reloadDnsmasq();
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

function ensureDnsmasqDirs(): void {
  foreach (array('/etc/dnsmasq.d', '/var/lib/misc') as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true))
      throw new RuntimeException("failed to create $dir");
  }
}

function buildDnsmasqFiles(): array {
  global $network, $myself;

  $dhcpHosts = array();
  $dhcpOptions = array();
  $dnsHosts = array(trim(($myself ?? '') . ' lan fenping fenping.lan'));

  $stmt = db()->query("
    SELECT
      ips.name AS name,
      IFNULL(ips.mac, '') AS mac,
      ips.ip AS ip,
      IFNULL(ips.router, '') AS router,
      IFNULL(ips.dns, '') AS dns,
      IFNULL(ni.filename, '') AS netboot_filename
    FROM ips
    LEFT JOIN netboot_images ni ON ni.id=ips.netboot_image_id
    WHERE ips.ip IS NOT NULL
      AND ips.name IS NOT NULL
  ");

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $name = (string)($row['name'] ?? '');
    $mac = strtolower((string)($row['mac'] ?? ''));
    $ip = (string)($row['ip'] ?? '');
    $router = (string)($row['router'] ?? '');
    $dns = (string)($row['dns'] ?? '');
    $netboot = (string)($row['netboot_filename'] ?? '');
    $tag = hostTag($ip);

    $dnsHosts[] = trim($ip . ' ' . dnsNames($name));

    if ($mac !== '')
      $dhcpHosts[] = implode(',', array($mac, "set:$tag", $ip, $name, 'infinite'));

    if ($router !== '')
      $dhcpOptions[] = "tag:$tag,option:router,$network.$router";

    if ($dns !== '')
      $dhcpOptions[] = "tag:$tag,option:dns-server," . preg_replace('/[ ;]+/', ',', $dns);

    if ($netboot !== '') {
      $dhcpOptions[] = "tag:$tag,option:tftp-server,$myself";
      $dhcpOptions[] = "tag:$tag,option:bootfile-name,$netboot";
    }
  }

  for ($i = 1; $i <= 255; $i++)
    $dnsHosts[] = "$network.$i _$i _$i.lan @$i @$i.lan ip$i ip$i.lan";

  return array(
    'dhcpHosts' => lines($dhcpHosts),
    'dhcpOptions' => lines($dhcpOptions),
    'dnsHosts' => lines($dnsHosts)
  );
}

function hostTag(string $value): string {
  return preg_replace('/[^A-Za-z0-9]/', '-', $value);
}

function dnsNames(string $name): string {
  if ($name === '')
    return '';
  return "$name $name.lan";
}

function lines(array $lines): string {
  if (count($lines) === 0)
    return '';
  return implode(PHP_EOL, $lines) . PHP_EOL;
}

function writeGeneratedFile(string $path, string $contents): void {
  $dir = dirname($path);
  $tmp = tempnam($dir, basename($path) . '.');
  if ($tmp === false)
    throw new RuntimeException("failed to create temporary file for $path");

  if (file_put_contents($tmp, $contents) === false) {
    @unlink($tmp);
    throw new RuntimeException("failed to write $path");
  }

  chmod($tmp, 0644);
  if (!rename($tmp, $path)) {
    @unlink($tmp);
    throw new RuntimeException("failed to replace $path");
  }
}

function dnsmasqPid(): ?int {
  if (!is_readable(DNSMASQ_PID))
    return null;

  $pid = intval(trim((string)file_get_contents(DNSMASQ_PID)));
  return $pid > 0 ? $pid : null;
}

function dnsmasqRunning(): bool {
  $pid = dnsmasqPid();
  if ($pid === null)
    return false;

  if (function_exists('posix_kill'))
    return posix_kill($pid, 0);

  exec('kill -0 ' . escapeshellarg((string)$pid) . ' 2>/dev/null', $output, $code);
  return $code === 0;
}

function reloadDnsmasq(): void {
  if ((getenv('DNSMASQ_RELOAD_MODE') ?: 'local') === 'none') {
    echo "dnsmasq reload delegated" . PHP_EOL;
    return;
  }

  if (!dnsmasqRunning()) {
    @unlink(DNSMASQ_PID);
    runCommand(array('dnsmasq', '--test', '--conf-file=' . DNSMASQ_CONF));
    runCommand(array('dnsmasq', '--conf-file=' . DNSMASQ_CONF));
    echo "dnsmasq started" . PHP_EOL;
    return;
  }

  $pid = dnsmasqPid();
  if ($pid === null)
    throw new RuntimeException('dnsmasq pid disappeared');

  if (function_exists('posix_kill')) {
    if (!posix_kill($pid, DNSMASQ_RELOAD_SIGNAL))
      throw new RuntimeException('failed to reload dnsmasq');
  } else {
    runCommand(array('kill', '-HUP', (string)$pid));
  }

  echo "dnsmasq reloaded" . PHP_EOL;
}

function runCommand(array $command): void {
  $line = implode(' ', array_map('escapeshellarg', $command));
  $output = array();
  $code = 0;
  exec($line . ' 2>&1', $output, $code);

  foreach ($output as $row)
    echo $row . PHP_EOL;

  if ($code !== 0)
    throw new RuntimeException(trim(implode(PHP_EOL, $output)) ?: "command failed: $line");
}
