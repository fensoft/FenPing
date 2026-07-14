<?php

declare(strict_types=1);

namespace FenPing\Inventory;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Docker\DockerNetworkCache;
use FenPing\Host\DiscoveredHostMetadataService;
use FenPing\Host\HostMetadataRepository;
use FenPing\Network\NetworkManager;
use FenPing\Scan\ProfileCatalog;
use FenPing\Scan\ScanPolicyService;
use FenPing\Scan\XmlCodec;
use FenPing\Status\StatusHistoryService;
use FenPing\Vendor\VendorLookup;
use PDO;

final readonly class InventoryReadService
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private NetworkManager $networks,
        private DockerNetworkCache $dockerNetworks,
        private HostMetadataRepository $metadata,
        private DiscoveredHostMetadataService $discoveredMetadata,
        private InventoryRowNormalizer $rows,
        private VendorLookup $vendors,
        private StatusHistoryService $history,
        private ScanPolicyService $scanPolicy,
        private XmlCodec $codec,
    ) {
    }

public function forNetwork(?string $networkCidr = null) {
  $selectedNetwork = $this->networks->forCidr($networkCidr, false);
  $dhcpNetwork = $selectedNetwork->cidr === $this->config->dhcpNetwork->cidr;
  $db = $this->database->connection();
  $managedMetadata = $this->metadata->managedHostMetadataMap();
  $deviceMetadata = $this->metadata->inventoryDeviceMetadataMap();
  $dockerContainers = $this->dockerNetworks->containers();
  $latestScans = $this->getLatestScans();
  $dockerGatewayTagsByIp = array();
  foreach ($this->dockerNetworks->gateways() as $gateway) {
    if ($gateway['cidr'] === $selectedNetwork->cidr)
      $dockerGatewayTagsByIp[$gateway['ip']][] =
        'gateway';
  }
  $dockerContainersByIp = array();
  $dockerContainerTagsByIp = array();
  foreach ($dockerContainers as $container) {
    if ($container['cidr'] === $selectedNetwork->cidr) {
      $dockerContainersByIp[$container['ip']][] = $container;
      $dockerContainerTagsByIp[$container['ip']][] =
        $this->rows->dockerAutomaticInventoryTag($container['network'], $container['container']);
    }
  }
  $approvedDevices = array();
  $approvalStmt = $db->query("SELECT mac FROM device_approvals");
  while ($approvedMac = $approvalStmt->fetchColumn()) {
    $approvedMac = strtolower(trim((string)$approvedMac));
    if ($approvedMac !== '')
      $approvedDevices[$approvedMac] = true;
  }

  $repeater = array();
  $stmt = $db->prepare("select mac, ip, name from ips where repeater=1");
  $stmt->execute();
  while ($data = $stmt->fetch()) {
    $mac = strtolower((string)($data[0] ?? ""));
    if ($mac != "")
      $repeater[$mac] = array("ip" => $data[1], "name" => $data[2]);
  }

  $ips = array();
  #cas normal
  $stmt = $db->prepare("select id, name, i.ip, i.mac, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and i.ip=p.ip and (p.mac=i.mac or p.mac='')");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC))
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  #derri�re un autre routeur
  $stmt = $db->prepare("select id, name, i.ip, p.mac, i.mac as mac_i, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and p.mac!='' and i.ip=p.ip and p.mac!=i.mac");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mac = (string)($data["mac"] ?? "");
    if (isset($repeater[$mac]))
      $data["via"] = "via " . $repeater[$mac]["name"];
    $data["mac"] = $data["mac_i"];
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #mauvaise ip
  $stmt = $db->prepare("select id, name, p.ip, i.ip as ip_should, p.mac, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.ip != i.ip and status = 'Up' and repeater is null");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #en db mais ne pingent pas
  $stmt = $db->prepare("select id, name, p.ip, p.mac, status, date, i.important, i.web, i.repeater from ping p left outer join ips i on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.status != 'Down' and id is null");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #dans les leases avec des new mac
  $macs = array();
  foreach ($ips as $key => $data) {
    $mac = strtoupper((string)($data["mac"] ?? ""));
    if ($mac != "")
      array_push($macs, $mac);
  }
  $stmt = $db->prepare("select NULL as id, `client-hostname` as name, ip, `hardware-ethernet` as mac, 'Down' as status, last_seen as date, 0 as important from leases where active=1 and ends > datetime('now', '-7 days')");
  $stmt->execute();
  while ($dhcpNetwork && ($data = $stmt->fetch(PDO::FETCH_ASSOC))) {
    $ip = $data["ip"] ?? "";
    if ($ip == "")
      continue;
    $mac = strtoupper((string)($data["mac"] ?? ""));
    if (!in_array($mac, $macs)) {
      if (!isset($ips[$ip]))
        $ips[$ip] = $data;
    }
    if (isset($ips[$ip]) && ($ips[$ip]["name"] ?? "") == "" && ($data["name"] ?? "") != "")
      $ips[$ip]["name"] = $data["name"];
  }
  #toujours up
  if ($this->config->applianceIp != "") {
    if (!isset($ips[$this->config->applianceIp]))
      $ips[$this->config->applianceIp] = array("ip" => $this->config->applianceIp);
    $ips[$this->config->applianceIp]["status"] = "Up";
  }
  if (!$dhcpNetwork) {
    $pingByIp = array();
    $pingStmt = $db->query("SELECT ip, mac, status, date FROM ping WHERE ip IS NOT NULL AND ip<>''");
    while ($pingRow = $pingStmt->fetch(PDO::FETCH_ASSOC))
      $pingByIp[(string)$pingRow['ip']] = $pingRow;

    foreach ($latestScans as $scanIp => $scan) {
      if (!$selectedNetwork->contains($scanIp) || ($scan['status'] ?? '') !== 'up' || isset($ips[$scanIp]))
        continue;
      $pingRow = $pingByIp[$scanIp] ?? array();
      $ips[$scanIp] = array(
        'ip' => $scanIp,
        'mac' => (string)($pingRow['mac'] ?? ''),
        'status' => (string)($pingRow['status'] ?? ''),
        'date' => $pingRow['date'] ?? null
      );
    }
  }
  $sorted_ips = array();
  foreach ($ips as $key => $data) {
    $hostId = isset($data['id']) ? (int)$data['id'] : 0;
    if ($hostId > 0 && isset($managedMetadata[$hostId])) {
      $data = array_merge($data, $managedMetadata[$hostId]);
      $identity = $this->discoveredMetadata->identity($data);
      if (!$dhcpNetwork && $identity !== null) {
        $data['device_identity'] = $identity;
        $data['metadata_editable'] = 1;
      }
      $data = $this->rows->withAutomaticInventoryTags($data, array_merge(
        $dockerGatewayTagsByIp[(string)$key] ?? array(),
        $dockerContainerTagsByIp[(string)$key] ?? array()
      ));
      $sorted_ips[] = $this->rows->normalizeInventoryRow($data, $key);
      continue;
    }

    $identities = $dockerContainersByIp[(string)$key] ?? array();
    if ($identities === array()) {
      $data = $this->rows->withAutomaticInventoryTags(
        $data,
        $dockerGatewayTagsByIp[(string)$key] ?? array()
      );
      $sorted_ips[] = $this->rows->normalizeInventoryRow($data, $key);
      continue;
    }

    foreach ($identities as $identity) {
      $row = $data;
      $row['device_identity'] = array(
        'network' => $identity['network'],
        'container' => $identity['container']
      );
      $row['metadata_editable'] = 1;
      $row['scan_profile'] = ProfileCatalog::UNMANAGED_DEFAULT;
      $row['scan_interval_hours'] = ProfileCatalog::UNMANAGED_INTERVAL_HOURS;
      $metadataKey = $this->metadata->inventoryDeviceMetadataKey($identity['network'], $identity['container']);
      if (isset($deviceMetadata[$metadataKey])) {
        $metadata = $deviceMetadata[$metadataKey];
        foreach (array(
          'display_name', 'important', 'web', 'scan_profile', 'scan_interval_hours',
          'notes', 'location', 'owner', 'model', 'icon', 'tags'
        ) as $field)
          $row[$field] = $metadata[$field];
      }
      $row = $this->rows->withAutomaticInventoryTags($row, array_merge(
        $dockerGatewayTagsByIp[(string)$key] ?? array(),
        array($this->rows->dockerAutomaticInventoryTag($identity['network'], $identity['container']))
      ));
      $sorted_ips[] = $this->rows->normalizeInventoryRow($row, $key);
    }
  }
  $sorted_ips = array_values(array_filter($sorted_ips, static fn(array $row): bool =>
    ip2long((string)$row['ip']) !== false
  ));
  usort($sorted_ips, function(array $left, array $right): int {
    $ipOrder = ((int)sprintf('%u', ip2long((string)$left['ip'])))
      <=> ((int)sprintf('%u', ip2long((string)$right['ip'])));
    if ($ipOrder !== 0)
      return $ipOrder;
    return strcmp(
      $this->metadata->inventoryDeviceMetadataKey((string)($left['device_identity']['network'] ?? ''), (string)($left['device_identity']['container'] ?? '')),
      $this->metadata->inventoryDeviceMetadataKey((string)($right['device_identity']['network'] ?? ''), (string)($right['device_identity']['container'] ?? ''))
    );
  });
  $old_ip = null;
  $old_network = null;
  $res = array();
  $stats = $this->history->statsMap();
  foreach ($sorted_ips as $key => $data) {
    $ip = $data["ip"];
    if (!$selectedNetwork->contains($ip))
      continue;
    $mac = trim((string)$data["mac"]);
    if ($mac === '' && $dhcpNetwork && $data['device_identity'] === null)
      continue;
    $normalizedMac = strtolower($mac);
    $managed = isset($data['id']) && $data['id'] !== null;
    if (!$this->rows->inventoryHostIsVisible($data, $managed))
      continue;
    $data['dhcp_managed'] = $managed ? 1 : 0;
    $data['network_is_dhcp'] = $dhcpNetwork ? 1 : 0;
    $data['approved'] = $mac !== '' && ($managed || isset($approvedDevices[$normalizedMac])) ? 1 : 0;
    $data['is_new'] = $mac !== '' && !$managed && !isset($approvedDevices[$normalizedMac]) ? 1 : 0;
    $categoryNetwork = substr($ip, 0, strrpos($ip, '.'));
    if ($categoryNetwork !== $old_network) {
      $old_network = $categoryNetwork;
      $old_ip = $categoryNetwork . '.0';
    }
    $stmt2 = $db->prepare("
      select ip_begin, ip_begin_full, type from (
        select
          ip_begin,
          case when ip_begin like '%.%' then ip_begin else :category_network || '.' || ip_begin end as ip_begin_full,
          type
        from `range`
      ) ranges
      where ipv4_num(:ip_begin) < ipv4_num(ip_begin_full)
        and ipv4_num(:ip_end) >= ipv4_num(ip_begin_full)
      order by ipv4_num(ip_begin_full) desc
      limit 1
    ");
    $stmt2->execute(array("category_network" => $categoryNetwork, "ip_begin" => $old_ip, "ip_end" => $ip));
    $data2 = $stmt2->fetch();
    if ($data2 != false) {
      $old_ip = $ip;
      $data["category"] = html_entity_decode((string)$data2["type"], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $data["category_ip"] = $data2["ip_begin"];
    }
    $data["vendor"] = "";
    $data["vendor"] = $mac === '' ? '' : $this->vendors->forMac($mac);
    if ($ip != "" && isset($stats[$ip])) {
      $data["stability"] = $stats[$ip];
      $data["stats"] = $stats[$ip]["label"];
      $data["stats2"] = $stats[$ip]["transitions"];
    }
    if ($ip != "" && !empty($latestScans[$ip]["result_available"])) {
      $data["xml"] = $ip;
    }
    if ($ip != "" && isset($latestScans[$ip])) {
      $data["scan"] = $latestScans[$ip];
    }
    array_push($res, $data);
  }
  return $res;
}

public function getLatestScans() {
  $stmt = $this->database->connection()->prepare("
    WITH latest_results AS (
      SELECT result.id, result.ip, result.mode, result.snapshot_id
      FROM scans result
      INNER JOIN (
        SELECT ip, MAX(id) AS id
        FROM scans
        WHERE state='complete'
          AND snapshot_id IS NOT NULL
        GROUP BY ip
      ) latest_result ON latest_result.id=result.id
    ),
    effective_snapshots AS (
      SELECT ip, snapshot_id
      FROM latest_results
      UNION ALL
      SELECT latest_results.ip, (
        SELECT deep.snapshot_id
        FROM scans deep
        WHERE deep.ip=latest_results.ip
          AND deep.id<latest_results.id
          AND deep.mode='deep'
          AND deep.state='complete'
          AND deep.snapshot_id IS NOT NULL
        ORDER BY deep.id DESC
        LIMIT 1
      ) AS snapshot_id
      FROM latest_results
      WHERE latest_results.mode IN ('quick', 'lightweight', 'standard')
    ),
    effective_ports AS (
      SELECT DISTINCT snapshots.ip, ports.protocol, ports.port
      FROM effective_snapshots snapshots
      INNER JOIN scan_snapshot_ports ports ON ports.snapshot_id=snapshots.snapshot_id
    ),
    effective_counts AS (
      SELECT ip, COUNT(*) AS ports_count
      FROM effective_ports
      GROUP BY ip
    )
    SELECT
      s.id,
      s.ip,
      s.mode,
      s.state,
      s.network,
      s.request_source,
      s.queued_at,
      s.progress_percent,
      s.progress_phase,
      s.progress_updated_at,
      s.cancel_requested_at,
      s.status,
      s.date_begin,
      s.date_end,
      s.duration,
      s.ports_count,
      s.snapshot_id,
      s.result_changed,
      s.error,
      latest_results.id IS NOT NULL AS result_available,
      COALESCE(effective_counts.ports_count, 0) AS effective_ports_count
    FROM scans s
    INNER JOIN (
      SELECT ip, MAX(id) id
      FROM scans
      GROUP BY ip
    ) latest ON latest.id=s.id
    LEFT JOIN latest_results ON latest_results.ip=s.ip
    LEFT JOIN effective_counts ON effective_counts.ip=s.ip
  ");
  $stmt->execute();

  $queueAnnotations = $this->scanPolicy->scanQueuePolicyState()['annotations'];
  $scans = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $resultAvailable = (int)$row['result_available'] === 1;
    $metadata = $this->scanPolicy->normalizeMetadata($row, $queueAnnotations);
    $metadata['result_available'] = $resultAvailable;
    $metadata['effective_ports_count'] = (int)$row['effective_ports_count'];
    $metadata['xml_usable'] = $resultAvailable;
    $metadata['xml_url'] = $resultAvailable ? $this->codec->url($metadata['ip']) : null;
    $metadata['xml'] = $metadata['xml_url'];
    $scans[$metadata['ip']] = $metadata;
  }
  return $scans;
}
}
