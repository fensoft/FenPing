<?php

declare(strict_types=1);

namespace FenPing\Anomaly;

use DateTimeImmutable;
use PDO;

final readonly class NetworkChurnAnalyzer
{
    private const BASELINE_HOURS = 24;
    private const CHURN_MINIMUM = 5;

    public function flappingMacs(PDO $database, string $network, DateTimeImmutable $at, int $threshold): array
    {
        $statement = $database->prepare("
            SELECT mac, change_type, unixepoch(occurred_at) AS occurred
            FROM network_presence_events
            WHERE network=:network AND occurred_at>:start AND occurred_at<=:end
            ORDER BY mac, occurred_at, id
        ");
        $statement->execute([
            'network' => $network,
            'start' => $at->modify('-24 hours')->format('Y-m-d H:i:s'),
            'end' => $at->format('Y-m-d H:i:s'),
        ]);
        $byMac = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) $byMac[strtolower((string)$row['mac'])][] = $row;
        $result = [];
        foreach ($byMac as $mac => $rows) {
            $kept = [];
            foreach ($rows as $row) {
                $last = $kept[array_key_last($kept)] ?? null;
                if ($last !== null
                    && $last['change_type'] !== $row['change_type']
                    && (int)$row['occurred'] - (int)$last['occurred'] <= 120) {
                    array_pop($kept);
                    continue;
                }
                $kept[] = $row;
            }
            if (count($kept) >= $threshold) $result[$mac] = count($kept);
        }
        return $result;
    }

    public function networkChurn(PDO $database, string $network, DateTimeImmutable $at): ?array
    {
        $baselineStart = $at->modify('-7 days')->format('Y-m-d H:00:00');
        $currentStart = $at->modify('-1 hour')->format('Y-m-d H:i:s');
        $end = $at->format('Y-m-d H:i:s');
        $runs = $database->prepare("
            SELECT DISTINCT strftime('%Y-%m-%d %H:00:00', observed_at) AS bucket
            FROM network_observation_runs
            WHERE network=:network AND observed_at>=:start AND observed_at<:current_start
            ORDER BY bucket
        ");
        $runs->execute(['network' => $network, 'start' => $baselineStart, 'current_start' => $currentStart]);
        $buckets = array_column($runs->fetchAll(PDO::FETCH_ASSOC), 'bucket');
        if (count($buckets) < self::BASELINE_HOURS) return null;
        $countsStatement = $database->prepare("
            SELECT strftime('%Y-%m-%d %H:00:00', occurred_at) AS bucket, COUNT(*) AS total
            FROM network_presence_events
            WHERE network=:network AND occurred_at>=:start AND occurred_at<:current_start
            GROUP BY bucket
        ");
        $countsStatement->execute(['network' => $network, 'start' => $baselineStart, 'current_start' => $currentStart]);
        $byBucket = [];
        while ($row = $countsStatement->fetch(PDO::FETCH_ASSOC)) {
            $byBucket[(string) $row['bucket']] = (int) $row['total'];
        }
        $counts = array_map(static fn(string $bucket): int => $byBucket[$bucket] ?? 0, $buckets);
        sort($counts, SORT_NUMERIC);
        $percentile = $counts[max(0, (int) ceil(0.95 * count($counts)) - 1)] ?? 0;
        $currentStatement = $database->prepare("
            SELECT COUNT(*) AS total, MAX(important) AS important
            FROM network_presence_events
            WHERE network=:network AND occurred_at>:start AND occurred_at<=:end
        ");
        $currentStatement->execute(['network' => $network, 'start' => $currentStart, 'end' => $end]);
        $current = $currentStatement->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'important' => 0];
        $total = (int) $current['total'];
        if ($total < self::CHURN_MINIMUM || $total <= $percentile) return null;
        return [
            'scope' => 'network', 'transition_count' => $total, 'window_hours' => 1,
            'baseline_hours' => count($buckets), 'baseline_percentile' => $percentile,
            'threshold' => max(self::CHURN_MINIMUM, $percentile + 1),
            'important' => (int) $current['important'] === 1,
        ];
    }
}
