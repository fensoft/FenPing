<?php

declare(strict_types=1);

namespace FenPing\Anomaly;

use DateTimeImmutable;
use DateTimeZone;
use FenPing\Database\DatabaseManager;
use FenPing\Network\Ipv4Network;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Vendor\VendorLookup;
use PDO;

final readonly class NetworkAnomalyService
{
    private const FLAP_TRANSITIONS = 6;
    public function __construct(
        private DatabaseManager $database,
        private VendorLookup $vendors,
        private LiveUpdatePublisher $liveUpdates,
        private NetworkChurnAnalyzer $churn,
    ) {}

    /** @return list<array<string, mixed>> */
    public function observePing(Ipv4Network $network, array $hosts, ?DateTimeImmutable $observedAt = null): array
    {
        $observedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $time = $observedAt->format('Y-m-d H:i:s');
        $pingIps = [];
        foreach ($hosts as $host) {
            $mac = $this->mac($host['mac'] ?? '');
            $ip = trim((string) ($host['ip'] ?? ''));
            $status = strtolower(trim((string) ($host['status'] ?? '')));
            if ($mac !== '' && $network->contains($ip) && in_array($status, ['up', 'arp'], true)) {
                $pingIps[$mac][$ip] = true;
            }
        }

        $leases = $this->activeLeases($network);
        $identityIps = $pingIps;
        $hostnames = [];
        foreach ($leases as $lease) {
            $mac = $lease['mac'];
            $identityIps[$mac][$lease['ip']] = true;
            if ($lease['hostname'] !== '') {
                $hostnames[$lease['hostname']][$mac] = true;
            }
        }
        $important = $this->importantMap();
        $events = $this->database->immediate(function (PDO $database) use (
            $network, $time, $pingIps, $identityIps, $hostnames, $important, $observedAt,
        ): array {
            if (!$this->initialized($database, $network->cidr)) {
                $this->bootstrap($database, $network->cidr, $time, $pingIps, $identityIps, $hostnames);
                return [];
            }

            $database->prepare('INSERT INTO network_observation_runs (network, observed_at) VALUES (:network, :time)')
                ->execute(['network' => $network->cidr, 'time' => $time]);
            $database->prepare('UPDATE network_anomaly_monitors SET last_observed_at=:time WHERE network=:network')
                ->execute(['network' => $network->cidr, 'time' => $time]);

            $states = $this->states($database, $network->cidr);
            $events = [];
            $allMacs = array_unique(array_merge(array_keys($states), array_keys($identityIps)));
            foreach ($allMacs as $mac) {
                $state = $states[$mac] ?? null;
                $present = isset($pingIps[$mac]);
                $wasPresent = (int) ($state['present'] ?? 0) === 1;
                $ips = array_keys($identityIps[$mac] ?? []);
                sort($ips, SORT_STRING);
                $isImportant = $this->identityImportant($mac, $ips, $important);

                if ($present !== $wasPresent) {
                    $database->prepare("
                        INSERT INTO network_presence_events (network, mac, ip, change_type, important, occurred_at)
                        VALUES (:network, :mac, :ip, :type, :important, :time)
                    ")->execute([
                        'network' => $network->cidr, 'mac' => $mac,
                        'ip' => count($ips) === 1 ? $ips[0] : ($state['current_ip'] ?? null),
                        'type' => $present ? 'arrival' : 'departure',
                        'important' => $isImportant ? 1 : 0, 'time' => $time,
                    ]);
                }

                $assignment = $this->vendors->assignmentForMac($mac);
                if ($assignment !== null) {
                    $knownAssignment = $state !== null && (string) ($state['vendor_key'] ?? '') !== '';
                    if (!$this->vendorKnown($database, $network->cidr, $assignment['key'])) {
                        $this->upsertVendor($database, $network->cidr, $assignment, $time);
                        if (!$knownAssignment) {
                            $event = $this->insertPointEvent($database, [
                                'network' => $network->cidr, 'anomaly_type' => 'unexpected_vendor',
                                'subtype' => 'network_first_seen', 'event_type' => 'detected',
                                'ip' => count($ips) === 1 ? $ips[0] : null, 'previous_ip' => null,
                                'mac' => $mac, 'hostname' => null, 'vendor' => $assignment['vendor'],
                                'important' => $isImportant ? 1 : 0,
                                'details' => ['vendor' => $assignment['vendor'], 'mac' => $mac, 'ips' => $ips],
                                'dedupe_key' => 'vendor|' . $network->cidr . '|' . $assignment['key'], 'time' => $time,
                            ]);
                            if ($event !== null) $events[] = $event;
                        }
                    } else {
                        $this->upsertVendor($database, $network->cidr, $assignment, $time);
                    }
                }

                $currentIp = count($ips) === 1 ? $ips[0] : ($state['current_ip'] ?? null);
                $previousIp = trim((string) ($state['current_ip'] ?? ''));
                if ($state !== null && count($ips) === 1 && $previousIp !== '' && $previousIp !== $currentIp) {
                    $event = $this->insertPointEvent($database, [
                        'network' => $network->cidr, 'anomaly_type' => 'ip_change', 'subtype' => 'mac_moved',
                        'event_type' => 'detected', 'ip' => $currentIp, 'previous_ip' => $previousIp,
                        'mac' => $mac, 'hostname' => null, 'vendor' => $assignment['vendor'] ?? ($state['vendor'] ?? null),
                        'important' => $isImportant ? 1 : 0,
                        'details' => ['mac' => $mac, 'previous_ip' => $previousIp, 'current_ip' => $currentIp],
                        'dedupe_key' => 'ip|' . $network->cidr . '|' . $mac . '|' . $previousIp . '|' . $currentIp . '|' . $time,
                        'time' => $time,
                    ]);
                    if ($event !== null) $events[] = $event;
                }

                if ($state === null && $ips === []) continue;
                $database->prepare("
                    INSERT INTO network_identity_state
                      (network, mac, current_ip, vendor_key, vendor, first_seen_at, last_seen_at, present)
                    VALUES (:network, :mac, :ip, :vendor_key, :vendor, :time, :time, :present)
                    ON CONFLICT(network, mac) DO UPDATE SET
                      current_ip=COALESCE(excluded.current_ip, network_identity_state.current_ip),
                      vendor_key=COALESCE(excluded.vendor_key, network_identity_state.vendor_key),
                      vendor=COALESCE(excluded.vendor, network_identity_state.vendor),
                      last_seen_at=CASE WHEN :seen=1 THEN excluded.last_seen_at ELSE network_identity_state.last_seen_at END,
                      present=excluded.present
                ")->execute([
                    'network' => $network->cidr, 'mac' => $mac, 'ip' => $currentIp,
                    'vendor_key' => $assignment['key'] ?? null, 'vendor' => $assignment['vendor'] ?? null,
                    'time' => $time, 'present' => $present ? 1 : 0, 'seen' => $ips !== [] ? 1 : 0,
                ]);
            }

            $activeConditions = [];
            foreach ($identityIps as $mac => $ipSet) {
                $ips = array_keys($ipSet);
                if (count($ips) < 2) continue;
                sort($ips, SORT_STRING);
                $key = 'duplicate_mac|' . $network->cidr . '|' . $mac;
                $activeConditions[$key] = true;
                $events = array_merge($events, $this->activateCondition(
                    $database, $key, $network->cidr, 'duplicate_identity', 'duplicate_mac', $mac,
                    $this->identityImportant($mac, $ips, $important), ['identity_type' => 'mac', 'identity' => $mac, 'ips' => $ips], $time,
                ));
            }
            foreach ($hostnames as $hostname => $macSet) {
                $macs = array_keys($macSet);
                if (count($macs) < 2) continue;
                sort($macs, SORT_STRING);
                $key = 'duplicate_hostname|' . $network->cidr . '|' . $hostname;
                $activeConditions[$key] = true;
                $hostnameImportant = false;
                foreach ($macs as $mac) $hostnameImportant = $hostnameImportant || $this->identityImportant($mac, [], $important);
                $events = array_merge($events, $this->activateCondition(
                    $database, $key, $network->cidr, 'duplicate_identity', 'duplicate_hostname', $hostname,
                    $hostnameImportant, ['identity_type' => 'hostname', 'identity' => $hostname, 'macs' => $macs], $time,
                ));
            }

            $flaps = $this->churn->flappingMacs($database, $network->cidr, $observedAt, self::FLAP_TRANSITIONS);
            foreach ($flaps as $mac => $count) {
                $key = 'device_flapping|' . $network->cidr . '|' . $mac;
                $activeConditions[$key] = true;
                $events = array_merge($events, $this->activateCondition(
                    $database, $key, $network->cidr, 'churn', 'device_flapping', $mac,
                    $this->identityImportant($mac, [], $important),
                    ['scope' => 'device', 'mac' => $mac, 'transition_count' => $count, 'window_hours' => 24, 'threshold' => self::FLAP_TRANSITIONS], $time,
                ));
            }

            $networkChurn = $this->churn->networkChurn($database, $network->cidr, $observedAt);
            if ($networkChurn !== null) {
                $key = 'network_churn|' . $network->cidr;
                $activeConditions[$key] = true;
                $events = array_merge($events, $this->activateCondition(
                    $database, $key, $network->cidr, 'churn', 'network_churn', $network->cidr,
                    $networkChurn['important'], $networkChurn, $time,
                ));
            }
            $events = array_merge($events, $this->resolveInactiveConditions($database, $network->cidr, $activeConditions, $time));
            $this->prune($database, $observedAt);
            return $events;
        });
        if ($events !== []) $this->liveUpdates->publish(LiveUpdateScope::Anomalies);
        return $events;
    }

    private function initialized(PDO $database, string $network): bool
    {
        $statement = $database->prepare('SELECT 1 FROM network_anomaly_monitors WHERE network=:network');
        $statement->execute(['network' => $network]);
        return $statement->fetchColumn() !== false;
    }

    private function bootstrap(PDO $database, string $network, string $time, array $pingIps, array $identityIps, array $hostnames): void
    {
        $database->prepare('INSERT INTO network_anomaly_monitors (network, initialized_at, last_observed_at) VALUES (:network, :time, :time)')
            ->execute(['network' => $network, 'time' => $time]);
        $database->prepare('INSERT INTO network_observation_runs (network, observed_at) VALUES (:network, :time)')
            ->execute(['network' => $network, 'time' => $time]);
        foreach ($identityIps as $mac => $ipSet) {
            $ips = array_keys($ipSet);
            sort($ips, SORT_STRING);
            $assignment = $this->vendors->assignmentForMac($mac);
            if ($assignment !== null) $this->upsertVendor($database, $network, $assignment, $time);
            $database->prepare("
                INSERT INTO network_identity_state
                  (network, mac, current_ip, vendor_key, vendor, first_seen_at, last_seen_at, present)
                VALUES (:network, :mac, :ip, :vendor_key, :vendor, :time, :time, :present)
            ")->execute([
                'network' => $network, 'mac' => $mac, 'ip' => count($ips) === 1 ? $ips[0] : null,
                'vendor_key' => $assignment['key'] ?? null, 'vendor' => $assignment['vendor'] ?? null,
                'time' => $time, 'present' => isset($pingIps[$mac]) ? 1 : 0,
            ]);
        }
        foreach ($identityIps as $mac => $ips) if (count($ips) > 1)
            $this->bootstrapCondition($database, 'duplicate_mac|' . $network . '|' . $mac, $network, 'duplicate_mac', $mac, ['identity_type' => 'mac', 'identity' => $mac, 'ips' => array_keys($ips)], $time);
        foreach ($hostnames as $hostname => $macs) if (count($macs) > 1)
            $this->bootstrapCondition($database, 'duplicate_hostname|' . $network . '|' . $hostname, $network, 'duplicate_hostname', $hostname, ['identity_type' => 'hostname', 'identity' => $hostname, 'macs' => array_keys($macs)], $time);
    }

    private function bootstrapCondition(PDO $database, string $key, string $network, string $subtype, string $identity, array $details, string $time): void
    {
        $database->prepare('INSERT INTO network_anomaly_conditions (condition_key, network, anomaly_type, subtype, identity_key, details_json, detected_at, last_seen_at, notified) VALUES (:key, :network, \'duplicate_identity\', :subtype, :identity, :details, :time, :time, 0)')
            ->execute(['key' => $key, 'network' => $network, 'subtype' => $subtype, 'identity' => $identity, 'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'time' => $time]);
    }

    private function activeLeases(Ipv4Network $network): array
    {
        $rows = $this->database->connection()->query("
            SELECT ip, `hardware-ethernet` AS mac, COALESCE(`client-hostname`, '') AS hostname
            FROM leases WHERE active=1 AND ends>CURRENT_TIMESTAMP
        ")->fetchAll(PDO::FETCH_ASSOC);
        $leases = [];
        foreach ($rows as $row) {
            $mac = $this->mac($row['mac'] ?? '');
            $ip = trim((string) ($row['ip'] ?? ''));
            if ($mac === '' || !$network->contains($ip)) continue;
            $hostname = strtolower(rtrim(trim((string) ($row['hostname'] ?? '')), '.'));
            $leases[] = ['ip' => $ip, 'mac' => $mac, 'hostname' => $hostname];
        }
        return $leases;
    }

    private function states(PDO $database, string $network): array
    {
        $statement = $database->prepare('SELECT * FROM network_identity_state WHERE network=:network');
        $statement->execute(['network' => $network]);
        $states = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) $states[strtolower((string) $row['mac'])] = $row;
        return $states;
    }

    private function importantMap(): array
    {
        $map = ['mac' => [], 'ip' => []];
        foreach ($this->database->connection()->query('SELECT mac, ip, important FROM ips')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) ($row['important'] ?? 0) !== 1) continue;
            $mac = $this->mac($row['mac'] ?? '');
            $ip = trim((string) ($row['ip'] ?? ''));
            if ($mac !== '') $map['mac'][$mac] = true;
            if ($ip !== '') $map['ip'][$ip] = true;
        }
        return $map;
    }

    private function identityImportant(string $mac, array $ips, array $important): bool
    {
        if (isset($important['mac'][$mac])) return true;
        foreach ($ips as $ip) if (isset($important['ip'][$ip])) return true;
        return false;
    }

    private function vendorKnown(PDO $database, string $network, string $key): bool
    {
        $statement = $database->prepare('SELECT 1 FROM network_vendor_baselines WHERE network=:network AND vendor_key=:key');
        $statement->execute(['network' => $network, 'key' => $key]);
        return $statement->fetchColumn() !== false;
    }

    private function upsertVendor(PDO $database, string $network, array $assignment, string $time): void
    {
        $database->prepare("
            INSERT INTO network_vendor_baselines (network, vendor_key, vendor, first_seen_at, last_seen_at)
            VALUES (:network, :key, :vendor, :time, :time)
            ON CONFLICT(network, vendor_key) DO UPDATE SET vendor=excluded.vendor, last_seen_at=excluded.last_seen_at
        ")->execute(['network' => $network, 'key' => $assignment['key'], 'vendor' => $assignment['vendor'], 'time' => $time]);
    }

    private function insertPointEvent(PDO $database, array $event): ?array
    {
        $statement = $database->prepare("
            INSERT OR IGNORE INTO network_anomaly_events
              (network, anomaly_type, subtype, event_type, ip, previous_ip, mac, hostname, vendor,
               important, details_json, dedupe_key, occurred_at)
            VALUES
              (:network, :anomaly_type, :subtype, :event_type, :ip, :previous_ip, :mac, :hostname, :vendor,
               :important, :details_json, :dedupe_key, :time)
        ");
        $parameters = $event;
        $parameters['details_json'] = json_encode($event['details'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        unset($parameters['details']);
        $statement->execute($parameters);
        if ($statement->rowCount() === 0) return null;
        return $this->eventById($database, (int) $database->lastInsertId());
    }

    private function activateCondition(PDO $database, string $key, string $network, string $type, string $subtype, string $identity, bool $important, array $details, string $time): array
    {
        $statement = $database->prepare('SELECT * FROM network_anomaly_conditions WHERE condition_key=:key');
        $statement->execute(['key' => $key]);
        $condition = $statement->fetch(PDO::FETCH_ASSOC);
        $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($condition !== false) {
            $database->prepare('UPDATE network_anomaly_conditions SET last_seen_at=:time, important=:important, details_json=:details WHERE condition_key=:key')
                ->execute(['time' => $time, 'important' => $important ? 1 : 0, 'details' => $json, 'key' => $key]);
            return [];
        }
        $database->prepare("
            INSERT INTO network_anomaly_conditions
              (condition_key, network, anomaly_type, subtype, identity_key, important, details_json, detected_at, last_seen_at, notified)
            VALUES (:key, :network, :type, :subtype, :identity, :important, :details, :time, :time, 1)
        ")->execute([
            'key' => $key, 'network' => $network, 'type' => $type, 'subtype' => $subtype,
            'identity' => $identity, 'important' => $important ? 1 : 0, 'details' => $json, 'time' => $time,
        ]);
        $event = $this->insertPointEvent($database, [
            'network' => $network, 'anomaly_type' => $type, 'subtype' => $subtype, 'event_type' => 'detected',
            'ip' => $details['ip'] ?? null, 'previous_ip' => null,
            'mac' => $details['mac'] ?? ($subtype === 'duplicate_mac' ? $identity : null),
            'hostname' => $subtype === 'duplicate_hostname' ? $identity : null, 'vendor' => null,
            'important' => $important ? 1 : 0, 'details' => $details,
            'dedupe_key' => $key . '|detected|' . $time, 'time' => $time,
        ]);
        return $event === null ? [] : [$event];
    }

    private function resolveInactiveConditions(PDO $database, string $network, array $active, string $time): array
    {
        $statement = $database->prepare('SELECT * FROM network_anomaly_conditions WHERE network=:network');
        $statement->execute(['network' => $network]);
        $events = [];
        while ($condition = $statement->fetch(PDO::FETCH_ASSOC)) {
            $key = (string) $condition['condition_key'];
            if (isset($active[$key])) continue;
            $details = json_decode((string) $condition['details_json'], true);
            if (!is_array($details)) $details = [];
            if ((int) $condition['notified'] === 1) {
                $event = $this->insertPointEvent($database, [
                    'network' => $network, 'anomaly_type' => $condition['anomaly_type'], 'subtype' => $condition['subtype'],
                    'event_type' => 'resolved', 'ip' => $details['ip'] ?? null, 'previous_ip' => null,
                    'mac' => $details['mac'] ?? ($condition['subtype'] === 'duplicate_mac' ? $condition['identity_key'] : null),
                    'hostname' => $condition['subtype'] === 'duplicate_hostname' ? $condition['identity_key'] : null,
                    'vendor' => null, 'important' => (int) $condition['important'], 'details' => $details,
                    'dedupe_key' => $key . '|resolved|' . $condition['detected_at'], 'time' => $time,
                ]);
                if ($event !== null) $events[] = $event;
            }
            $database->prepare('DELETE FROM network_anomaly_conditions WHERE condition_key=:key')->execute(['key' => $key]);
        }
        return $events;
    }

    private function eventById(PDO $database, int $id): array
    {
        $statement = $database->prepare('SELECT * FROM network_anomaly_events WHERE id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['important'] = (int) ($row['important'] ?? 0);
        $row['details'] = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        unset($row['details_json'], $row['dedupe_key']);
        return $row;
    }

    private function prune(PDO $database, DateTimeImmutable $at): void
    {
        $database->prepare('DELETE FROM network_anomaly_events WHERE occurred_at<:cutoff')
            ->execute(['cutoff' => $at->modify('-30 days')->format('Y-m-d H:i:s')]);
        $cutoff = $at->modify('-8 days')->format('Y-m-d H:i:s');
        $database->prepare('DELETE FROM network_presence_events WHERE occurred_at<:cutoff')->execute(['cutoff' => $cutoff]);
        $database->prepare('DELETE FROM network_observation_runs WHERE observed_at<:cutoff')->execute(['cutoff' => $cutoff]);
    }

    private function mac(mixed $value): string
    {
        $mac = strtolower(str_replace('-', ':', trim(is_scalar($value) ? (string) $value : '')));
        return preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) === 1 ? $mac : '';
    }
}
