<?php

declare(strict_types=1);

namespace FenPing\Host;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Network\NetworkManager;
use InvalidArgumentException;

final readonly class CategoryRepository
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private NetworkManager $networks,
    ) {
    }

    public function create(mixed $ip, mixed $name): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO "range" (ip_begin, "type") VALUES (:ip, :name)',
        );
        $statement->execute([
            'ip' => $this->validatedIp($ip),
            'name' => trim((string) $name),
        ]);
    }

    public function rename(mixed $ip, mixed $name): int
    {
        $original = trim((string) $ip);
        if ($original === '') {
            throw new InvalidArgumentException('category ip is required');
        }
        $name = trim((string) $name);
        if ($name === '') {
            throw new InvalidArgumentException('category name is required');
        }
        $normalized = $this->validatedIp($original);
        $short = str_replace($this->config->network . '.', '', $normalized);
        $statement = $this->database->connection()->prepare(
            'UPDATE "range" SET "type"=:name
             WHERE ip_begin=:ip OR ip_begin=:normalized OR ip_begin=:short',
        );
        $statement->execute([
            'name' => $name,
            'ip' => $original,
            'normalized' => $normalized,
            'short' => $short,
        ]);
        return $statement->rowCount() > 0 ? 1 : 0;
    }

    public function delete(mixed $ip): void
    {
        $original = trim((string) $ip);
        $normalized = $this->validatedIp($original);
        $short = str_replace($this->config->network . '.', '', $normalized);
        $statement = $this->database->connection()->prepare(
            'DELETE FROM "range" WHERE ip_begin=:ip OR ip_begin=:normalized OR ip_begin=:short',
        );
        $statement->execute([
            'ip' => $original,
            'normalized' => $normalized,
            'short' => $short,
        ]);
    }

    private function validatedIp(mixed $value): string
    {
        $ip = trim((string) $value);
        if ($ip !== '' && !str_contains($ip, '.')) {
            $ip = $this->config->network . '.' . $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('invalid category ip');
        }
        $this->networks->assertDhcpIp($ip);
        return $ip;
    }
}
