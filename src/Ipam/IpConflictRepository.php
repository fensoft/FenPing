<?php

declare(strict_types=1);

namespace FenPing\Ipam;

use DateTimeImmutable;
use DateTimeZone;
use FenPing\Database\DatabaseManager;
use FenPing\Network\Ipv4Network;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Realtime\NullLiveUpdatePublisher;
use PDO;

final readonly class IpConflictRepository
{
    private LiveUpdatePublisher $liveUpdates;

    public function __construct(private DatabaseManager $database, ?LiveUpdatePublisher $liveUpdates = null)
    {
        $this->liveUpdates = $liveUpdates ?? new NullLiveUpdatePublisher();
    }

    public function reconcile(Ipv4Network $network, array $conflicts, DateTimeImmutable $observedAt): array
    {
        $timestamp = $this->timestamp($observedAt);
        $transitions = $this->database->immediate(function (PDO $database) use ($network, $conflicts, $timestamp): array {
            $monitor = $database->prepare("
                INSERT INTO ip_conflict_monitor (network, last_attempt_at, last_success_at, last_error_at, error)
                VALUES (:network, :observed_at, :observed_at, NULL, NULL)
                ON CONFLICT(network) DO UPDATE SET
                  last_attempt_at=excluded.last_attempt_at,
                  last_success_at=excluded.last_success_at,
                  last_error_at=NULL,
                  error=NULL
            ");
            $monitor->execute(['network' => $network->cidr, 'observed_at' => $timestamp]);

            $activeStatement = $database->prepare("
                SELECT id, ip FROM ip_conflicts
                WHERE network=:network AND resolved_at IS NULL
            ");
            $activeStatement->execute(['network' => $network->cidr]);
            $active = [];
            while ($row = $activeStatement->fetch(PDO::FETCH_ASSOC)) {
                $active[(string) $row['ip']] = (int) $row['id'];
            }

            $insertConflict = $database->prepare("
                INSERT INTO ip_conflicts (network, ip, detected_at, last_seen_at)
                VALUES (:network, :ip, :observed_at, :observed_at)
            ");
            $updateConflict = $database->prepare("
                UPDATE ip_conflicts SET last_seen_at=:observed_at WHERE id=:id
            ");
            $upsertDevice = $database->prepare("
                INSERT INTO ip_conflict_devices (conflict_id, mac, first_seen_at, last_seen_at)
                VALUES (:conflict_id, :mac, :observed_at, :observed_at)
                ON CONFLICT(conflict_id, mac) DO UPDATE SET last_seen_at=excluded.last_seen_at
            ");
            $resolveConflict = $database->prepare("
                UPDATE ip_conflicts SET resolved_at=:observed_at WHERE id=:id AND resolved_at IS NULL
            ");

            ksort($conflicts, SORT_NATURAL);
            $transitions = [];
            foreach ($conflicts as $ip => $macs) {
                $id = $active[$ip] ?? null;
                if ($id === null) {
                    $insertConflict->execute([
                        'network' => $network->cidr,
                        'ip' => $ip,
                        'observed_at' => $timestamp,
                    ]);
                    $id = (int) $database->lastInsertId();
                    $transitions[] = ['id' => $id, 'type' => 'detected'];
                } else {
                    $updateConflict->execute(['observed_at' => $timestamp, 'id' => $id]);
                    unset($active[$ip]);
                }
                foreach (array_keys($macs) as $mac) {
                    $upsertDevice->execute([
                        'conflict_id' => $id,
                        'mac' => $mac,
                        'observed_at' => $timestamp,
                    ]);
                }
            }

            foreach ($active as $id) {
                $resolveConflict->execute(['observed_at' => $timestamp, 'id' => $id]);
                if ($resolveConflict->rowCount() === 1) {
                    $transitions[] = ['id' => $id, 'type' => 'resolved'];
                }
            }
            return $transitions;
        });
        $this->liveUpdates->publish(LiveUpdateScope::Conflicts);
        return $transitions;
    }

    public function recordFailure(Ipv4Network $network, DateTimeImmutable $attemptedAt, string $error): void
    {
        $this->database->immediate(function (PDO $database) use ($network, $attemptedAt, $error): void {
            $statement = $database->prepare("
                INSERT INTO ip_conflict_monitor (network, last_attempt_at, last_error_at, error)
                VALUES (:network, :attempted_at, :attempted_at, :error)
                ON CONFLICT(network) DO UPDATE SET
                  last_attempt_at=excluded.last_attempt_at,
                  last_error_at=excluded.last_error_at,
                  error=excluded.error
            ");
            $statement->execute([
                'network' => $network->cidr,
                'attempted_at' => $this->timestamp($attemptedAt),
                'error' => substr(trim($error), 0, 500),
            ]);
        });
        $this->liveUpdates->publish(LiveUpdateScope::Conflicts);
    }

    public function active(?string $network = null): array
    {
        $sql = "SELECT * FROM ip_conflicts WHERE resolved_at IS NULL";
        $params = [];
        if ($network !== null) {
            $sql .= ' AND network=:network';
            $params['network'] = $network;
        }
        $sql .= ' ORDER BY ipv4_num(ip), detected_at';
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($params);
        $episodes = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $episodes[] = $this->episode($row, true);
        }
        return $episodes;
    }

    public function recent(int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));
        $statement = $this->database->connection()->prepare("
            SELECT * FROM ip_conflicts
            WHERE detected_at>=datetime('now', '-$hours hours')
               OR resolved_at>=datetime('now', '-$hours hours')
            ORDER BY MAX(detected_at, COALESCE(resolved_at, detected_at)) DESC, id DESC
        ");
        $statement->execute();
        $cutoff = time() - $hours * 3600;
        $events = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $episode = $this->episode($row, false);
            $detected = strtotime((string) $row['detected_at']);
            if ($detected !== false && $detected >= $cutoff) {
                $events[] = $this->event($episode, 'detected', (string) $row['detected_at']);
            }
            $resolved = strtotime((string) ($row['resolved_at'] ?? ''));
            if ($resolved !== false && $resolved >= $cutoff) {
                $events[] = $this->event($episode, 'resolved', (string) $row['resolved_at']);
            }
        }
        usort($events, static fn(array $left, array $right): int => strcmp($right['occurred_at'], $left['occurred_at'])
            ?: strcmp($right['event_id'], $left['event_id']));
        return $events;
    }

    public function transitionDetails(array $transitions): array
    {
        $details = [];
        foreach ($transitions as $transition) {
            $statement = $this->database->connection()->prepare('SELECT * FROM ip_conflicts WHERE id=:id');
            $statement->execute(['id' => (int) ($transition['id'] ?? 0)]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                continue;
            }
            $episode = $this->episode($row, false);
            $type = (string) ($transition['type'] ?? 'detected');
            $occurredAt = $type === 'resolved' ? (string) $row['resolved_at'] : (string) $row['detected_at'];
            $details[] = $this->event($episode, $type, $occurredAt);
        }
        return $details;
    }

    public function monitors(): array
    {
        $rows = [];
        $statement = $this->database->connection()->query('SELECT * FROM ip_conflict_monitor ORDER BY network');
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $lastSuccess = (string) ($row['last_success_at'] ?? '');
            $lastError = (string) ($row['last_error_at'] ?? '');
            $healthy = $lastError === '' || ($lastSuccess !== '' && strcmp($lastSuccess, $lastError) >= 0);
            $rows[] = [
                'network' => (string) $row['network'],
                'status' => $healthy ? 'ok' : 'degraded',
                'last_attempt_at' => $row['last_attempt_at'],
                'last_success_at' => $row['last_success_at'],
                'last_error_at' => $row['last_error_at'],
            ];
        }
        return $rows;
    }

    private function episode(array $row, bool $currentOnly): array
    {
        $devices = [];
        $statement = $this->database->connection()->prepare("
            SELECT d.mac, d.first_seen_at, d.last_seen_at,
              COALESCE(NULLIF((SELECT i.name FROM ips i WHERE LOWER(i.mac)=LOWER(d.mac) ORDER BY i.id DESC LIMIT 1), ''),
                NULLIF((SELECT l.`client-hostname` FROM leases l WHERE LOWER(l.`hardware-ethernet`)=LOWER(d.mac)
                  ORDER BY l.active DESC, l.last_seen DESC LIMIT 1), ''), '') AS name,
              COALESCE(NULLIF((SELECT i.ip FROM ips i WHERE LOWER(i.mac)=LOWER(d.mac) ORDER BY i.id DESC LIMIT 1), ''), '') AS managed_ip
            FROM ip_conflict_devices d
            WHERE d.conflict_id=:conflict_id
            ORDER BY d.mac
        ");
        $statement->execute(['conflict_id' => (int) $row['id']]);
        while ($device = $statement->fetch(PDO::FETCH_ASSOC)) {
            $current = (string) $device['last_seen_at'] === (string) $row['last_seen_at'];
            if ($currentOnly && !$current) {
                continue;
            }
            $devices[] = [
                'mac' => strtolower((string) $device['mac']),
                'name' => (string) $device['name'],
                'managed_ip' => (string) $device['managed_ip'],
                'first_seen_at' => $device['first_seen_at'],
                'last_seen_at' => $device['last_seen_at'],
                'current' => $current,
            ];
        }
        return [
            'id' => (int) $row['id'],
            'network' => (string) $row['network'],
            'ip' => (string) $row['ip'],
            'detected_at' => $row['detected_at'],
            'last_seen_at' => $row['last_seen_at'],
            'resolved_at' => $row['resolved_at'],
            'devices' => $devices,
        ];
    }

    private function event(array $episode, string $type, string $occurredAt): array
    {
        return $episode + [
            'event_id' => 'conflict-' . $episode['id'] . '-' . $type,
            'type' => $type,
            'occurred_at' => $occurredAt,
            'occurred' => strtotime($occurredAt) ?: 0,
        ];
    }

    private function timestamp(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
