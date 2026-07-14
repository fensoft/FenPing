<?php

declare(strict_types=1);

namespace FenPing\Ping;

use FenPing\Database\DatabaseManager;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use PDO;

final class PingRepository
{
    private ?array $statements = null;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LiveUpdatePublisher $liveUpdates,
    ) {
    }

    public function save(array $hosts): void
    {
        if ($hosts === []) {
            return;
        }
        $this->database->immediate(function () use ($hosts): void {
            $statements = $this->statements();
            foreach ($hosts as $host) {
                $this->saveHost($host, $statements);
            }
        });
        $this->liveUpdates->publish(LiveUpdateScope::Status);
    }

    private function statements(): array
    {
        if ($this->statements !== null) {
            return $this->statements;
        }
        $database = $this->database->connection();
        return $this->statements = [
            'upsert' => $database->prepare("
                INSERT INTO ping (ip, mac, status)
                VALUES (:ip, NULLIF(:mac, ''), :status)
                ON CONFLICT(ip) DO UPDATE SET
                  date=CASE
                    WHEN COALESCE(excluded.mac, ping.mac) IS NOT ping.mac
                      OR excluded.status IS NOT ping.status
                    THEN CURRENT_TIMESTAMP ELSE ping.date END,
                  mac=COALESCE(excluded.mac, ping.mac),
                  status=excluded.status
            "),
            'latestStatus' => $database->prepare("
                SELECT id, ip, mac, status, date_begin, date_end
                FROM stats WHERE ip=:ip ORDER BY id DESC LIMIT 1
            "),
            'extendStatus' => $database->prepare("
                UPDATE stats SET date_end=CURRENT_TIMESTAMP, nb_scan=nb_scan+1
                WHERE id=:id AND (date_end IS NULL OR date_end<=datetime('now', '-1 day'))
            "),
            'insertStatus' => $database->prepare(
                'INSERT INTO stats (ip, mac, status) VALUES (:ip, :mac, :status)',
            ),
        ];
    }

    private function saveHost(array $host, array $statements): void
    {
        $ip = (string) ($host['ip'] ?? '');
        $mac = (string) ($host['mac'] ?? '');
        $status = trim((string) ($host['status'] ?? '')) ?: 'Down';

        $statements['upsert']->execute(['ip' => $ip, 'mac' => $mac, 'status' => $status]);
        $statements['latestStatus']->execute(['ip' => $ip]);
        $latest = $statements['latestStatus']->fetch(PDO::FETCH_ASSOC);
        $normalizedMac = $mac === '' ? null : $mac;
        if ($latest !== false
            && (string) $latest['ip'] === $ip
            && ($latest['mac'] ?? null) === $normalizedMac
            && (string) $latest['status'] === $status) {
            $statements['extendStatus']->execute(['id' => $latest['id']]);
            return;
        }
        $statements['insertStatus']->execute([
            'ip' => $ip,
            'mac' => $normalizedMac,
            'status' => $status,
        ]);
    }
}
