<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Audit\AuditLogService;
use FenPing\Host\CategoryRepository;
use FenPing\Api\HttpException;
use FenPing\Host\HostService;
use FenPing\Network\NetworkPolicyException;
use InvalidArgumentException;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Status\StatusHistoryService;

final readonly class HostController implements Controller
{
    public function __construct(
        private HostService $hosts,
        private CategoryRepository $categories,
        private StatusHistoryService $history,
        private AuditLogService $audit,
    ) {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/history/{ip:ipv4}', fn(Request $request, array $params): array => $this->history->response($params['ip'])),
            new Route('GET', '/hosts/{id:int}/detail', fn(Request $request, array $params): array => $this->hosts->detail($params['id'])),
            new Route('GET', '/hosts/by-ip/{ip:ipv4}/detail', fn(Request $request, array $params): array => $this->hosts->detailByIp($params['ip'], $request->query)),
            new Route('GET', '/hosts/{id:int}', fn(Request $request, array $params): array => $this->hosts->get($params['id'])),
            new Route(
                'POST',
                '/hosts',
                fn(Request $request, array $params): array => $this->createHost($request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'PUT',
                '/hosts/{id:int}/metadata',
                fn(Request $request, array $params): array => $this->updateMetadata($params['id'], $request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'PUT',
                '/hosts/{id:int}',
                fn(Request $request, array $params): array => $this->updateHost($params['id'], $request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'DELETE',
                '/hosts/{id:int}',
                fn(Request $request, array $params): array => $this->deleteHost($params['id']),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'POST',
                '/categories',
                fn(Request $request, array $params): array => $this->createCategory($request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'PUT',
                '/categories',
                fn(Request $request, array $params): array => $this->renameCategory($request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'DELETE',
                '/categories',
                fn(Request $request, array $params): array => $this->deleteCategory($request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
        ];
    }

    private function createHost(Request $request): array
    {
        $result = $this->hosts->create($request->body());
        $host = $this->hosts->get((int) $result['id']);
        $this->audit->record(
            'host.created', 'host', $result['id'], 'Created DHCP reservation for ' . $this->hostLabel($host),
            ['after' => $this->hostSnapshot($host)],
        );
        return $result;
    }

    private function updateHost(int $id, Request $request): array
    {
        $before = $this->hosts->get($id);
        $result = $this->hosts->update($id, $request->body());
        $after = $this->hosts->get($id);
        $this->audit->record(
            'host.updated', 'host', $id, 'Updated host ' . $this->hostLabel($after),
            ['changes' => $this->changes($this->hostSnapshot($before), $this->hostSnapshot($after))],
        );
        return $result;
    }

    private function updateMetadata(int $id, Request $request): array
    {
        $before = $this->hosts->get($id);
        $result = $this->hosts->updateDiscoveredMetadata($id, $request->body());
        $after = $this->hosts->get($id);
        $this->audit->record(
            'host.metadata_updated', 'host', $id, 'Updated inventory metadata for ' . $this->hostLabel($after),
            ['changes' => $this->changes($this->hostSnapshot($before), $this->hostSnapshot($after))],
        );
        return $result;
    }

    private function deleteHost(int $id): array
    {
        $before = $this->hosts->get($id);
        $result = $this->hosts->delete($id);
        $this->audit->record(
            'host.deleted', 'host', $id, 'Deleted DHCP reservation for ' . $this->hostLabel($before),
            ['before' => $this->hostSnapshot($before)],
        );
        return $result;
    }

    private function hostSnapshot(array $host): array
    {
        return array_intersect_key($host, array_flip([
            'id', 'ip', 'mac', 'name', 'display_name', 'router', 'dns', 'repeater', 'important', 'web',
            'netboot_image_id', 'scan_profile', 'scan_interval_hours', 'notes', 'location', 'owner',
            'model', 'icon', 'tags',
        ]));
    }

    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }
        return $changes;
    }

    private function hostLabel(array $host): string
    {
        return (string) (($host['display_name'] ?? '') ?: ($host['name'] ?? '') ?: ($host['ip'] ?? 'host'));
    }
    private function createCategory(Request $request): array
    {
        $body = $request->body();
        $this->categoryCall(fn() => $this->categories->create($body['ip'] ?? '', $body['name'] ?? ''));
        return ['created' => true];
    }

    private function renameCategory(Request $request): array
    {
        $body = $request->body();
        $updated = $this->categoryCall(fn(): int => $this->categories->rename($body['ip'] ?? '', $body['name'] ?? ''));
        if ($updated < 1) throw new HttpException(404, 'category not found');
        return ['renamed' => true];
    }

    private function deleteCategory(Request $request): array
    {
        $body = $request->body();
        $this->categoryCall(fn() => $this->categories->delete($body['ip'] ?? ''));
        return ['deleted' => true];
    }

    private function categoryCall(callable $operation): mixed
    {
        try { return $operation(); }
        catch (NetworkPolicyException $error) { throw new HttpException($error->httpStatus, $error->getMessage()); }
        catch (InvalidArgumentException $error) { throw new HttpException(400, $error->getMessage()); }
    }
}
