<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Network\NetworkManager;
use PDO;

final readonly class ScanPolicyService
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private NetworkManager $networks,
        private XmlCodec $codec,
    ) {
    }

public function scanQueuePolicyState(): array {
  $database = $this->database->connection();
  $runningTotal = (int)$database->query("SELECT COUNT(*) FROM scans WHERE state='running'")->fetchColumn();
  $runningByNetwork = array();
  $stmt = $database->query("SELECT network, COUNT(*) AS total FROM scans WHERE state='running' GROUP BY network");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $runningByNetwork[(string)$row['network']] = (int)$row['total'];

  $startsByNetwork = array();
  $stmt = $database->query("
    SELECT network, unixepoch(date_begin) AS started_at
    FROM scans
    WHERE request_source='scheduled' AND date_begin>=datetime('now', '-24 hours')
    ORDER BY date_begin, id
  ");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $startsByNetwork[(string)$row['network']][] = (int)$row['started_at'];

  $networkSummaries = array();
  foreach ($this->networks->configured() as $network) {
    $limits = $this->config->scanLimitsForNetwork($network->cidr);
    $starts = $startsByNetwork[$network->cidr] ?? array();
    $eligibleAt = null;
    if (count($starts) >= $limits['daily_budget']) {
      $index = max(0, count($starts) - $limits['daily_budget']);
      $eligibleAt = gmdate('Y-m-d H:i:s', $starts[$index] + 86400);
    }
    $networkSummaries[] = array(
      'network' => $network->cidr,
      'running' => $runningByNetwork[$network->cidr] ?? 0,
      'concurrency_limit' => $limits['concurrency'],
      'scheduled_starts_24h' => count($starts),
      'daily_budget' => $limits['daily_budget'],
      'budget_eligible_at' => $eligibleAt
    );
  }

  $annotations = array();
  $position = 0;
  $projectedRunningTotal = $runningTotal;
  $projectedRunningByNetwork = $runningByNetwork;
  $stmt = $database->query("
    SELECT id, ip, network, request_source
    FROM scans
    WHERE state='queued'
    ORDER BY CASE mode WHEN 'quick' THEN 0 WHEN 'lightweight' THEN 0 WHEN 'standard' THEN 1 ELSE 2 END, id
  ");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $network = trim((string)$row['network']);
    if ($network === '')
      $network = $this->scanNetworkCidrForIp((string)$row['ip']);
    $limits = $this->config->scanLimitsForNetwork($network);
    $starts = $startsByNetwork[$network] ?? array();
    if ($row['request_source'] === 'scheduled' && count($starts) >= $limits['daily_budget']) {
      $index = max(0, count($starts) - $limits['daily_budget']);
      $annotations[(int)$row['id']] = array(
        'queue_position' => null,
        'queue_reason' => 'daily_budget',
        'budget_eligible_at' => gmdate('Y-m-d H:i:s', $starts[$index] + 86400),
        'progress_phase' => 'waiting_budget'
      );
      continue;
    }

    $position++;
    if (($projectedRunningByNetwork[$network] ?? 0) >= $limits['concurrency'])
      $reason = 'network_concurrency';
    elseif ($projectedRunningTotal >= $this->config->scanGlobalConcurrency)
      $reason = 'global_concurrency';
    else {
      $reason = 'ready';
      $projectedRunningTotal++;
      $projectedRunningByNetwork[$network] = ($projectedRunningByNetwork[$network] ?? 0) + 1;
    }
    $annotations[(int)$row['id']] = array(
      'queue_position' => $position,
      'queue_reason' => $reason,
      'budget_eligible_at' => null,
      'progress_phase' => match ($reason) {
        'network_concurrency' => 'waiting_network',
        'global_concurrency' => 'waiting_global',
        default => 'queued'
      }
    );
  }

  return array(
    'annotations' => $annotations,
    'summary' => array(
      'global' => array('running' => $runningTotal, 'concurrency_limit' => $this->config->scanGlobalConcurrency),
      'networks' => $networkSummaries
    )
  );
}

public function scanPolicySummary(): array {
  return $this->scanQueuePolicyState()['summary'];
}

public function normalizeMetadata(array $metadata, ?array $queueAnnotations = null): array {
  $metadata['id'] = isset($metadata['id']) ? (int)$metadata['id'] : null;
  $metadata['duration'] = isset($metadata['duration']) && $metadata['duration'] !== null ? (int)$metadata['duration'] : null;
  $metadata['ports_count'] = isset($metadata['ports_count']) ? (int)$metadata['ports_count'] : 0;
  $metadata['snapshot_id'] = isset($metadata['snapshot_id']) && $metadata['snapshot_id'] !== null ? (int)$metadata['snapshot_id'] : null;
  $metadata['result_changed'] = (int)($metadata['result_changed'] ?? 0);
  $metadata['network'] = trim((string)($metadata['network'] ?? ''));
  if ($metadata['network'] === '' && !empty($metadata['ip']))
    $metadata['network'] = $this->scanNetworkCidrForIp((string)$metadata['ip']);
  $metadata['request_source'] = (string)($metadata['request_source'] ?? 'legacy');
  $metadata['progress_percent'] = isset($metadata['progress_percent']) && $metadata['progress_percent'] !== null
    ? (int)$metadata['progress_percent']
    : (($metadata['state'] ?? '') === 'complete' ? 100 : 0);
  $metadata['progress_phase'] = (string)($metadata['progress_phase'] ?? $metadata['state'] ?? 'queued');
  $metadata['progress_updated_at'] = $metadata['progress_updated_at'] ?? null;
  $metadata['cancel_requested'] = !empty($metadata['cancel_requested_at']);
  unset($metadata['cancel_requested_at']);
  $metadata['queue_position'] = null;
  $metadata['queue_reason'] = null;
  $metadata['budget_eligible_at'] = null;
  if (($metadata['state'] ?? '') === 'queued' && $metadata['id'] !== null) {
    $queueAnnotations ??= $this->scanQueuePolicyState()['annotations'];
    if (isset($queueAnnotations[$metadata['id']]))
      $metadata = array_merge($metadata, $queueAnnotations[$metadata['id']]);
  }
  $metadata['result_available'] = (int)($metadata['snapshot_id'] ?? 0) > 0;
  $metadata['xml_usable'] = $metadata['result_available'];
  $metadata['xml_url'] = $metadata['xml_usable'] && isset($metadata['ip'], $metadata['id'])
    ? $this->codec->url($metadata['ip'], $metadata['id'])
    : null;
  $metadata['xml'] = $metadata['xml_url'];
  return $metadata;
}
private function scanNetworkCidrForIp(string $ip): string {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new \InvalidArgumentException('invalid scan ip');
  $parts = explode('.', $ip);
  return implode('.', array_slice($parts, 0, 3)) . '.0/24';
}
}
