<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Dns\DnsOverrideParser;
use PDO;

final readonly class ConfigRenderer
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private HostValidator $validator,
        private DnsOverrideParser $dnsOverrides,
    ) {
    }

public function buildDnsmasqFiles(): array {
  $applianceIp = $this->validator->ip($this->config->applianceIp ?? '', true);
  $dhcpHosts = array();
  $dhcpOptions = array();
  $dnsHostRecords = array(array('ip' => $applianceIp, 'names' => array('lan', 'fenping', 'fenping.lan')));

  $stmt = $this->database->connection()->query("
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
    $name = $this->validator->hostname($row['name'] ?? '', false);
    $mac = $this->validator->mac($row['mac'] ?? '', false);
    $ip = $this->validator->ip($row['ip'] ?? '', true);
    if (!$this->config->dhcpNetwork->contains($ip))
      continue;
    $router = $this->validator->router($row['router'] ?? '');
    $dns = $this->validator->dnsServers($row['dns'] ?? '');
    $netboot = $this->validator->bootFilename($row['netboot_filename'] ?? '');
    $tag = $this->hostTag($ip);

    if ($name !== '')
      $dnsHostRecords[] = array('ip' => $ip, 'names' => array($name, $name . '.lan'));

    if ($mac !== '') {
      $reservation = array($mac, "set:$tag", $ip);
      if ($name !== '')
        $reservation[] = $name;
      $reservation[] = 'infinite';
      $dhcpHosts[] = implode(',', $reservation);
    }

    if ($router !== null) {
      $routerIp = $this->validator->ip(($this->config->network ?? '') . '.' . $router, true);
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
    $ip = $this->validator->ip(($this->config->network ?? '') . '.' . $i, true);
    $dnsHostRecords[] = array('ip' => $ip, 'names' => array("_$i", "_$i.lan", "@$i", "@$i.lan", "ip$i", "ip$i.lan"));
  }

  $groups = $this->database->connection()->query(
    'SELECT id, name, enabled, contents FROM dns_override_groups ORDER BY name COLLATE NOCASE, id'
  )->fetchAll(PDO::FETCH_ASSOC);
  $baseNames = array_merge(...array_map(static fn(array $record): array => $record['names'], $dnsHostRecords));
  $customDns = $this->dnsOverrides->compile($groups, $baseNames);
  $overridden = array_fill_keys($customDns['owned_names'], true);
  $dnsHosts = array();
  foreach ($dnsHostRecords as $record) {
    $names = array_values(array_filter(
      $record['names'],
      static fn(string $name): bool => !isset($overridden[strtolower($name)])
    ));
    if ($names !== array())
      $dnsHosts[] = $record['ip'] . ' ' . implode(' ', $names);
  }

  return array(
    'dhcpHosts' => $this->lines($dhcpHosts),
    'dhcpOptions' => $this->lines($dhcpOptions),
    'dnsHosts' => $this->lines($dnsHosts),
    'customDns' => $customDns['config'],
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
