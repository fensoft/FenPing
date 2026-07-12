<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

trait DhcpRendererBehavior
{
public const DNSMASQ_CONF = '/etc/dnsmasq.d/fenping.conf';
public const DNSMASQ_DHCP_HOSTS = '/etc/dnsmasq.d/fenping.dhcp-hosts';
public const DNSMASQ_DHCP_OPTS = '/etc/dnsmasq.d/fenping.dhcp-opts';
public const DNSMASQ_HOSTS = '/etc/dnsmasq.d/fenping.hosts';
public const DNSMASQ_PID = '/var/run/dnsmasq.pid';
public const DNSMASQ_RELOAD_SIGNAL = 1;
public const DNSMASQ_UPDATE_LOCK = '/tmp/fenping-dnsmasq-update.lock';

public function runHostsCommand(array $args = array()): int {
  $lock = null;

  try {
    $this->ensureDnsmasqDirs();

    if ($args === array('--apply-pending')) {
      $this->applyDnsmasqCandidate($this->dnsmasqPendingDir());
      echo "pending dnsmasq files applied" . PHP_EOL;
      return 0;
    }

    if ($args === array('--sync-locked')) {
      $this->syncDnsmasqFromDatabase();
      return 0;
    }

    if (count($args) !== 0) {
      fwrite(STDERR, "Usage: php cli.php hosts" . PHP_EOL);
      return 2;
    }

    $lock = $this->acquireDnsmasqUpdateLock();
    $this->syncDnsmasqFromDatabase();
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  } finally {
    $this->releaseDnsmasqUpdateLock($lock);
  }
}

public function syncDnsmasqFromDatabase(): void {
  $candidateDir = $this->createDnsmasqCandidateDir();

  try {
    $this->prepareDnsmasqCandidate($this->buildDnsmasqFiles(), $candidateDir);
    $this->applyDnsmasqCandidate($candidateDir);
    echo "dnsmasq files written" . PHP_EOL;
  } finally {
    $this->removeDnsmasqCandidateDir($candidateDir);
  }
}

public function ensureDnsmasqDirs(): void {
  foreach (array('/etc/dnsmasq.d', '/var/lib/misc') as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true))
      throw new RuntimeException("failed to create $dir");
  }
}

public function validateDhcpHostCreate($ip, $mac): array {
  $normalizedIp = $this->normalizeDhcpIp($ip, true);
  $this->networks->assertDhcpIp($normalizedIp);
  return array(
    'ip' => $normalizedIp,
    'mac' => $this->normalizeDhcpMac($mac, true)
  );
}

public function validateDhcpHostEdit($ip, $mac, $name, $router, $dns): array {
  $normalizedIp = $this->normalizeDhcpIp($ip, true);
  $this->networks->assertDhcpIp($normalizedIp);
  return array(
    'ip' => $normalizedIp,
    'mac' => $this->normalizeDhcpMac($mac, true),
    'name' => $this->normalizeDhcpHostname($name, false),
    'router' => $this->normalizeDhcpRouter($router),
    'dns' => $this->normalizeDhcpDnsServers($dns)
  );
}

public function normalizeDhcpIp($value, bool $required): ?string {
  $ip = $this->dhcpScalarText($value, 'ip');
  if ($ip === '') {
    if ($required)
      throw new InvalidArgumentException('ip is required');
    return null;
  }

  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new InvalidArgumentException('invalid ip');
  return $ip;
}

public function normalizeDhcpMac($value, bool $required): string {
  $mac = strtolower(str_replace('-', ':', $this->dhcpScalarText($value, 'mac')));
  if ($mac === '') {
    if ($required)
      throw new InvalidArgumentException('mac is required');
    return '';
  }

  if (preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) !== 1)
    throw new InvalidArgumentException('invalid mac; expected six hexadecimal octets');
  return $mac;
}

public function normalizeDhcpHostname($value, bool $required): string {
  $name = $this->dhcpScalarText($value, 'name');
  if ($name === '') {
    if ($required)
      throw new InvalidArgumentException('host name is required');
    return '';
  }

  if (strlen($name) > 50 || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $name) !== 1)
    throw new InvalidArgumentException('invalid host name; use a single DNS label containing letters, numbers, or hyphens');
  return $name;
}

public function normalizeDhcpRouter($value): ?string {
  $router = $this->dhcpScalarText($value, 'router');
  if ($router === '')
    return null;
  if (!ctype_digit($router) || (int)$router < 1 || (int)$router > 254)
    throw new InvalidArgumentException('invalid router; expected a host number from 1 to 254');
  return (string)(int)$router;
}

public function normalizeDhcpDnsServers($value): ?string {
  $dns = $this->dhcpScalarText($value, 'dns');
  if ($dns === '')
    return null;

  $servers = preg_split('/[\s,;]+/', $dns, -1, PREG_SPLIT_NO_EMPTY);
  if ($servers === false || count($servers) === 0)
    throw new InvalidArgumentException('invalid dns servers');

  $normalized = array();
  foreach ($servers as $server) {
    if (filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
      throw new InvalidArgumentException("invalid dns server: $server");
    $normalized[$server] = true;
  }
  return implode(' ', array_keys($normalized));
}

public function normalizeDhcpBootFilename($value): string {
  $filename = $this->dhcpScalarText($value, 'netboot filename');
  if ($filename === '')
    return '';
  if (basename($filename) !== $filename || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) !== 1)
    throw new InvalidArgumentException('invalid netboot filename');
  return $filename;
}

public function dhcpScalarText($value, string $field): string {
  if ($value === null)
    return '';
  if (!is_scalar($value))
    throw new InvalidArgumentException("invalid $field");
  return trim((string)$value);
}

public function buildDnsmasqFiles(): array {
  $applianceIp = $this->normalizeDhcpIp($this->config->applianceIp ?? '', true);
  $dhcpHosts = array();
  $dhcpOptions = array();
  $dnsHosts = array($applianceIp . ' lan fenping fenping.lan');

  $stmt = $this->db()->query("
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
  ");

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $name = $this->normalizeDhcpHostname($row['name'] ?? '', false);
    $mac = $this->normalizeDhcpMac($row['mac'] ?? '', false);
    $ip = $this->normalizeDhcpIp($row['ip'] ?? '', true);
    if (!$this->config->dhcpNetwork->contains($ip))
      continue;
    $router = $this->normalizeDhcpRouter($row['router'] ?? '');
    $dns = $this->normalizeDhcpDnsServers($row['dns'] ?? '');
    $netboot = $this->normalizeDhcpBootFilename($row['netboot_filename'] ?? '');
    $tag = $this->hostTag($ip);

    if ($name !== '')
      $dnsHosts[] = $ip . ' ' . $this->dnsNames($name);

    if ($mac !== '') {
      $reservation = array($mac, "set:$tag", $ip);
      if ($name !== '')
        $reservation[] = $name;
      $reservation[] = 'infinite';
      $dhcpHosts[] = implode(',', $reservation);
    }

    if ($router !== null) {
      $routerIp = $this->normalizeDhcpIp(($this->config->network ?? '') . '.' . $router, true);
      $dhcpOptions[] = "tag:$tag,option:router,$routerIp";
    }

    if ($dns !== null)
      $dhcpOptions[] = "tag:$tag,option:dns-server," . str_replace(' ', ',', $dns);

    if ($netboot !== '') {
      $dhcpOptions[] = "tag:$tag,option:tftp-server,$applianceIp";
      $dhcpOptions[] = "tag:$tag,option:bootfile-name,$netboot";
    }
  }

  for ($i = 1; $i <= 255; $i++) {
    $ip = $this->normalizeDhcpIp(($this->config->network ?? '') . '.' . $i, true);
    $dnsHosts[] = "$ip _$i _$i.lan @$i @$i.lan ip$i ip$i.lan";
  }

  return array(
    'dhcpHosts' => $this->lines($dhcpHosts),
    'dhcpOptions' => $this->lines($dhcpOptions),
    'dnsHosts' => $this->lines($dnsHosts)
  );
}

public function hostTag(string $value): string {
  return preg_replace('/[^A-Za-z0-9]/', '-', $value);
}

public function dnsNames(string $name): string {
  return "$name $name.lan";
}

public function lines(array $lines): string {
  if (count($lines) === 0)
    return '';
  return implode(PHP_EOL, $lines) . PHP_EOL;
}
}
