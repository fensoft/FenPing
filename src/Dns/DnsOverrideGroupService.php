<?php

declare(strict_types=1);

namespace FenPing\Dns;

use FenPing\Database\DatabaseManager;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;

final readonly class DnsOverrideGroupService
{
    public function __construct(private DatabaseManager $database, private DnsOverrideParser $parser)
    {
    }

    public function all(): array
    {
        $rows = $this->database->connection()->query(
            'SELECT id, name, enabled, contents, created_at, updated_at FROM dns_override_groups ORDER BY name COLLATE NOCASE, id',
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->normalizeRow($row), $rows);
    }

    public function create(array $input): array
    {
        $data = $this->input($input);
        $this->ensureUniqueName($data['name']);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO dns_override_groups (name, enabled, contents) VALUES (:name, :enabled, :contents)',
        );
        $statement->execute($data);
        return $this->find((int) $this->database->connection()->lastInsertId());
    }

    public function update(int $id, array $input): array
    {
        $this->find($id);
        $data = $this->input($input);
        $this->ensureUniqueName($data['name'], $id);
        $statement = $this->database->connection()->prepare(
            'UPDATE dns_override_groups SET name=:name, enabled=:enabled, contents=:contents, updated_at=CURRENT_TIMESTAMP WHERE id=:id',
        );
        $statement->execute([...$data, 'id' => $id]);
        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $statement = $this->database->connection()->prepare('DELETE FROM dns_override_groups WHERE id=:id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new OutOfBoundsException('DNS group not found');
        }
    }

    public function find(int $id): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, name, enabled, contents, created_at, updated_at FROM dns_override_groups WHERE id=:id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new OutOfBoundsException('DNS group not found');
        }
        return $this->normalizeRow($row);
    }

    private function input(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $contents = str_replace(["\r\n", "\r"], "\n", (string) ($input['contents'] ?? ''));
        if ($name === '' || strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException('DNS group name must be between 1 and 80 characters');
        }
        $enabledValue = $input['enabled'] ?? true;
        if (!is_bool($enabledValue) && !in_array($enabledValue, [0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException('DNS group enabled value must be boolean');
        }
        $this->parser->parse($contents, $name);
        return ['name' => $name, 'enabled' => (int) (bool) $enabledValue, 'contents' => $contents];
    }

    private function ensureUniqueName(string $name, ?int $exceptId = null): void
    {
        $sql = 'SELECT id FROM dns_override_groups WHERE name=:name COLLATE NOCASE';
        $params = ['name' => $name];
        if ($exceptId !== null) {
            $sql .= ' AND id<>:id';
            $params['id'] = $exceptId;
        }
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($params);
        if ($statement->fetchColumn() !== false) {
            throw new InvalidArgumentException('A DNS group with this name already exists');
        }
    }

    private function normalizeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['enabled'] = (bool) $row['enabled'];
        $row['record_count'] = count($this->parser->parse((string) $row['contents'], (string) $row['name']));
        return $row;
    }
}
