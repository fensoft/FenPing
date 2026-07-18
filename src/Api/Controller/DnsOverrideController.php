<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Audit\AuditLogService;
use FenPing\Dhcp\MutationCoordinator;
use FenPing\Dns\DnsOverrideGroupService;
use FenPing\Realtime\LiveUpdateScope;
use InvalidArgumentException;
use OutOfBoundsException;

final readonly class DnsOverrideController implements Controller
{
    public function __construct(
        private DnsOverrideGroupService $groups,
        private MutationCoordinator $mutations,
        private AuditLogService $audit,
    ) {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/dns/groups', fn(Request $request, array $params): array => ['groups' => $this->groups->all()]),
            new Route(
                'POST',
                '/dns/groups',
                fn(Request $request, array $params): array => $this->create($request->body()),
                AuthPolicy::Session,
                [LiveUpdateScope::Dns],
            ),
            new Route(
                'PUT',
                '/dns/groups/{id:int}',
                fn(Request $request, array $params): array => $this->update($params['id'], $request->body()),
                AuthPolicy::Session,
                [LiveUpdateScope::Dns],
            ),
            new Route(
                'DELETE',
                '/dns/groups/{id:int}',
                fn(Request $request, array $params): array => $this->delete($params['id']),
                AuthPolicy::Session,
                [LiveUpdateScope::Dns],
            ),
        ];
    }

    private function create(array $body): array
    {
        return $this->call(function () use ($body): array {
            $change = $this->mutations->commit(fn(): array => $this->groups->create($body));
            $group = $change['result'];
            $this->audit->record(
                'dns_override.created', 'dns_override_group', $group['id'], 'Created DNS override group ' . $group['name'],
                ['after' => $this->snapshot($group)],
            );
            return ['group' => $change['result'], 'log' => $change['log']];
        });
    }

    private function update(int $id, array $body): array
    {
        return $this->call(function () use ($id, $body): array {
            $before = $this->groups->find($id);
            $change = $this->mutations->commit(fn(): array => $this->groups->update($id, $body));
            $group = $change['result'];
            $this->audit->record(
                'dns_override.updated', 'dns_override_group', $id, 'Updated DNS override group ' . $group['name'],
                ['before' => $this->snapshot($before), 'after' => $this->snapshot($group)],
            );
            return ['group' => $change['result'], 'log' => $change['log']];
        });
    }

    private function delete(int $id): array
    {
        return $this->call(function () use ($id): array {
            $before = $this->groups->find($id);
            $change = $this->mutations->commit(function () use ($id): bool {
                $this->groups->delete($id);
                return true;
            });
            $this->audit->record(
                'dns_override.deleted', 'dns_override_group', $id, 'Deleted DNS override group ' . $before['name'],
                ['before' => $this->snapshot($before)],
            );
            return ['deleted' => true, 'log' => $change['log']];
        });
    }

    private function call(callable $operation): array
    {
        try {
            return $operation();
        } catch (OutOfBoundsException $error) {
            throw new HttpException(404, $error->getMessage());
        } catch (InvalidArgumentException $error) {
            $status = str_contains($error->getMessage(), 'already exists') ? 409 : 400;
            throw new HttpException($status, $error->getMessage());
        }
    }

    private function snapshot(array $group): array
    {
        return [
            'name' => $group['name'],
            'enabled' => $group['enabled'],
            'record_count' => $group['record_count'],
            'contents_sha256' => hash('sha256', (string) $group['contents']),
        ];
    }
}
