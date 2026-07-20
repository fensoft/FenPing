<?php

declare(strict_types=1);

namespace FenPing\Service;

use FenPing\Database\DatabaseManager;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;

final readonly class MonitoredServiceRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

    public function all(): array
    {
        $rows = $this->database->connection()->query(
            'SELECT * FROM monitored_services ORDER BY source DESC, name COLLATE NOCASE, id',
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalize(...), $rows);
    }

    public function find(int $id): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM monitored_services WHERE id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new OutOfBoundsException('monitored service not found');
        }
        return $this->normalize($row);
    }

    public function pin(array $discovered): array
    {
        $ip = (string) ($discovered['ip'] ?? '');
        $protocol = strtolower((string) ($discovered['protocol'] ?? ''));
        $port = (int) ($discovered['port'] ?? 0);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !in_array($protocol, ['tcp', 'udp'], true) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('invalid discovered service');
        }
        try {
            $statement = $this->database->connection()->prepare(<<<'SQL'
                INSERT INTO monitored_services (
                  source, type, name, target, port, protocol, service, version, tunnel, last_seen_at
                ) VALUES (
                  'discovered', 'discovered', :name, :target, :port, :protocol, :service, :version, :tunnel, :last_seen_at
                )
                SQL);
            $statement->execute([
                'name' => $this->nullableText($discovered['name'] ?? null, 120),
                'target' => $ip,
                'port' => $port,
                'protocol' => $protocol,
                'service' => $this->nullableText($discovered['service'] ?? null, 120),
                'version' => $this->nullableText($discovered['version'] ?? null, 500),
                'tunnel' => $this->nullableText($discovered['tunnel'] ?? null, 40),
                'last_seen_at' => $discovered['scan_date'] ?? null,
            ]);
        } catch (PDOException $error) {
            if (str_contains(strtolower($error->getMessage()), 'unique')) {
                throw new InvalidArgumentException('service is already important');
            }
            throw $error;
        }
        return $this->find((int) $this->database->connection()->lastInsertId());
    }

    public function createManual(array $input): array
    {
        $data = $this->manualInput($input);
        try {
            $statement = $this->database->connection()->prepare(<<<'SQL'
                INSERT INTO monitored_services (source, type, name, target, port, check_status)
                VALUES ('manual', :type, :name, :target, :port, 'pending')
                SQL);
            $statement->execute($data);
        } catch (PDOException $error) {
            if (str_contains(strtolower($error->getMessage()), 'unique')) {
                throw new InvalidArgumentException('manual service already exists');
            }
            throw $error;
        }
        return $this->find((int) $this->database->connection()->lastInsertId());
    }

    public function updateManual(int $id, array $input): array
    {
        $existing = $this->find($id);
        if ($existing['source'] !== 'manual') {
            throw new InvalidArgumentException('discovered services cannot be edited');
        }
        $data = $this->manualInput($input);
        try {
            $statement = $this->database->connection()->prepare(<<<'SQL'
                UPDATE monitored_services
                SET type=:type, name=:name, target=:target, port=:port,
                    check_status='pending', check_detail=NULL, observed_ip=NULL,
                    last_checked_at=NULL, updated_at=CURRENT_TIMESTAMP
                WHERE id=:id AND source='manual'
                SQL);
            $statement->execute([...$data, 'id' => $id]);
        } catch (PDOException $error) {
            if (str_contains(strtolower($error->getMessage()), 'unique')) {
                throw new InvalidArgumentException('manual service already exists');
            }
            throw $error;
        }
        return $this->find($id);
    }

    public function delete(int $id, string $source): array
    {
        $record = $this->find($id);
        if ($record['source'] !== $source) {
            throw new InvalidArgumentException("service is not $source");
        }
        $statement = $this->database->connection()->prepare('DELETE FROM monitored_services WHERE id=:id');
        $statement->execute(['id' => $id]);
        return $record;
    }

    public function recordCheck(int $id, ServiceProbeResult $result): array
    {
        $this->database->beginImmediate();
        try {
            $before = $this->find($id);
            if ($before['source'] !== 'manual') {
                throw new InvalidArgumentException('only manual services can be checked');
            }
            $statement = $this->database->connection()->prepare(<<<'SQL'
                UPDATE monitored_services
                SET check_status=:status, check_detail=:detail, observed_ip=:observed_ip,
                    last_checked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                WHERE id=:id
                SQL);
            $statement->execute([
                'status' => $result->healthy ? 'healthy' : 'unhealthy',
                'detail' => $this->nullableText($result->detail, 240),
                'observed_ip' => $this->nullableText($result->observedIp, 64),
                'id' => $id,
            ]);
            $after = $this->find($id);
            $this->database->commit();
            return ['before' => $before, 'after' => $after];
        } catch (\Throwable $error) {
            $this->database->rollback();
            throw $error;
        }
    }

    public function observeScan(string $ip, array $scan): void
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            UPDATE monitored_services
            SET service=:service, version=:version, tunnel=:tunnel,
                last_seen_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
            WHERE source='discovered' AND target=:target AND protocol=:protocol AND port=:port
            SQL);
        foreach ($scan['ports'] ?? [] as $port) {
            if (strtolower((string) ($port['state'] ?? '')) !== 'open') {
                continue;
            }
            $statement->execute([
                'service' => $this->nullableText($port['service'] ?? null, 120),
                'version' => $this->nullableText($port['details'] ?? null, 500),
                'tunnel' => $this->nullableText($port['tunnel'] ?? null, 40),
                'target' => $ip,
                'protocol' => strtolower((string) ($port['protocol'] ?? '')),
                'port' => (int) ($port['port'] ?? 0),
            ]);
        }
    }

    private function manualInput(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException('service name must be between 1 and 80 characters');
        }
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        if (!in_array($type, ['https', 'ssh', 'proxy', 'socks5'], true)) {
            throw new InvalidArgumentException('invalid manual service type');
        }
        if ($type === 'https') {
            $target = trim((string) ($input['url'] ?? ''));
            $this->validateHttpsUrl($target);
            return ['type' => $type, 'name' => $name, 'target' => $target, 'port' => null];
        }
        $target = trim((string) ($input['host'] ?? ''));
        $this->validateHost($target);
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new InvalidArgumentException('service port must be between 1 and 65535');
        }
        return ['type' => $type, 'name' => $name, 'target' => $target, 'port' => $port];
    }

    private function validateHttpsUrl(string $url): void
    {
        if ($url === '' || strlen($url) > 2048) {
            throw new InvalidArgumentException('HTTPS URL must be between 1 and 2048 characters');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new InvalidArgumentException('invalid credential-free HTTPS URL');
        }
    }

    private function validateHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return;
        }
        if ($host === '' || strlen($host) > 253 || preg_match(
            '/^(?=.{1,253}\.?$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/',
            $host,
        ) !== 1) {
            throw new InvalidArgumentException('invalid service host');
        }
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['port'] = $row['port'] === null ? null : (int) $row['port'];
        $row['important'] = true;
        if ($row['source'] === 'manual') {
            $row['url'] = $row['type'] === 'https' ? $row['target'] : null;
            $row['host'] = $row['type'] === 'https' ? null : $row['target'];
        }
        return $row;
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = substr(trim((string) $value), 0, $limit);
        return $value === '' ? null : $value;
    }
}
