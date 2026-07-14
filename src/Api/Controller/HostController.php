<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\Request;
use FenPing\Api\Route;
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
                fn(Request $request, array $params): array => $this->hosts->create($request->body()),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'PUT',
                '/hosts/{id:int}/metadata',
                fn(Request $request, array $params): array => $this->hosts->updateDiscoveredMetadata($params['id'], $request->body()),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'PUT',
                '/hosts/{id:int}',
                fn(Request $request, array $params): array => $this->hosts->update($params['id'], $request->body()),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'DELETE',
                '/hosts/{id:int}',
                fn(Request $request, array $params): array => $this->hosts->delete($params['id']),
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
