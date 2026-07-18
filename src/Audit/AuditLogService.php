<?php

declare(strict_types=1);

namespace FenPing\Audit;

use FenPing\Api\RequestContext;
use FenPing\Database\DatabaseManager;
use PDO;
use Throwable;

final readonly class AuditLogService
{
    private const PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private DatabaseManager $database)
    {
    }

    public function record(
        string $action,
        string $resourceType,
        string|int|null $resourceId,
        string $summary,
        array $details = [],
        ?string $actor = null,
    ): void {
        try {
            $request = RequestContext::request();
            $server = $request?->server ?? [];
            $statement = $this->database->connection()->prepare("
                INSERT INTO audit_events (
                  actor, remote_address, user_agent, action, resource_type, resource_id, summary, details_json
                ) VALUES (
                  :actor, :remote_address, :user_agent, :action, :resource_type, :resource_id, :summary, :details_json
                )
            ");
            $statement->execute([
                'actor' => $this->text($actor ?? ($request === null ? 'system' : 'admin'), 40),
                'remote_address' => $this->nullableText($server['REMOTE_ADDR'] ?? null, 64),
                'user_agent' => $this->nullableText($server['HTTP_USER_AGENT'] ?? null, 512),
                'action' => $this->text($action, 80),
                'resource_type' => $this->text($resourceType, 80),
                'resource_id' => $this->nullableText($resourceId, 200),
                'summary' => $this->text($summary, 500),
                'details_json' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $error) {
            error_log('audit log write failed: ' . $error->getMessage());
        }
    }

    public function page(array $query): array
    {
        $page = max(1, $this->positiveInteger($query['page'] ?? null, 1));
        $perPage = min(self::MAX_PAGE_SIZE, max(1, $this->positiveInteger($query['per_page'] ?? null, self::PAGE_SIZE)));
        $action = $this->filterValue($query['action'] ?? null);
        $resourceType = $this->filterValue($query['resource_type'] ?? null);
        $search = trim((string) ($query['search'] ?? ''));
        if (strlen($search) > 200) {
            $search = substr($search, 0, 200);
        }

        $where = [];
        $parameters = [];
        if ($action !== '') {
            $where[] = 'action=:action';
            $parameters['action'] = $action;
        }
        if ($resourceType !== '') {
            $where[] = 'resource_type=:resource_type';
            $parameters['resource_type'] = $resourceType;
        }
        if ($search !== '') {
            $where[] = "(summary LIKE :search ESCAPE '\\' OR resource_id LIKE :search ESCAPE '\\' OR remote_address LIKE :search ESCAPE '\\')";
            $parameters['search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $count = $this->database->connection()->prepare('SELECT COUNT(*) FROM audit_events' . $clause);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $statement = $this->database->connection()->prepare("
            SELECT id, occurred_at, actor, remote_address, user_agent, action,
                   resource_type, resource_id, summary, details_json
            FROM audit_events{$clause}
            ORDER BY occurred_at DESC, id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        $events = array_map($this->normalize(...), $statement->fetchAll(PDO::FETCH_ASSOC));
        return [
            'events' => $events,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'total' => $total],
            'filters' => [
                'actions' => $this->distinct('action'),
                'resource_types' => $this->distinct('resource_type'),
            ],
        ];
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $details = json_decode((string) $row['details_json'], true);
        $row['details'] = is_array($details) ? $details : [];
        unset($row['details_json']);
        return $row;
    }

    private function distinct(string $column): array
    {
        return $this->database->connection()->query(
            "SELECT DISTINCT {$column} FROM audit_events ORDER BY {$column}",
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function positiveInteger(mixed $value, int $default): int
    {
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function filterValue(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return preg_match('/^[a-z0-9_.-]{1,80}$/', $value) === 1 ? $value : '';
    }

    private function text(mixed $value, int $limit): string
    {
        return substr(trim((string) $value), 0, $limit);
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->text($value, $limit);
        return $text === '' ? null : $text;
    }
}
