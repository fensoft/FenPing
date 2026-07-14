<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Api\JsonResponse;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Config\AppConfig;
use FenPing\Health\HealthService;
use FenPing\Inventory\InventoryService;
use FenPing\Network\NetworkManager;
use FenPing\Network\NetworkPolicyException;
use FenPing\Ping\PingRefreshGateway;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Status\NotificationService;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

final readonly class SystemController implements Controller
{
    public function __construct(
        private AppConfig $config,
        private NetworkManager $networks,
        private HealthService $health,
        private InventoryService $inventory,
        private NotificationService $notifications,
        private PingRefreshGateway $ping,
    ) {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/health', fn(Request $request, array $params): array => $this->health->status()),
            new Route('GET', '/health/live', fn(Request $request, array $params): array => $this->health->liveness()),
            new Route('GET', '/health/ready', function (Request $request, array $params): JsonResponse {
                $readiness = $this->health->readiness();
                return new JsonResponse($readiness, $readiness['ready'] ? 200 : 503);
            }),
            new Route('GET', '/inventory', fn(Request $request, array $params): array => $this->inventory($request)),
            new Route(
                'PUT',
                '/inventory/device-metadata',
                fn(Request $request, array $params): array => $this->updateDeviceMetadata($request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'POST',
                '/inventory/saved-filters',
                fn(Request $request, array $params): array => $this->createSavedFilter($request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'PUT',
                '/inventory/saved-filters/{id:int}',
                fn(Request $request, array $params): array => $this->updateSavedFilter($params['id'], $request),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'DELETE',
                '/inventory/saved-filters/{id:int}',
                fn(Request $request, array $params): array => $this->deleteSavedFilter($params['id']),
                AuthPolicy::BodyOrSession,
                [LiveUpdateScope::Hosts],
            ),
            new Route('GET', '/notify', fn(Request $request, array $params): array => $this->notifications->recent()),
            new Route(
                'GET',
                '/notify/telegram/chats',
                fn(Request $request, array $params): array => $this->telegramChats(),
                AuthPolicy::Session,
            ),
            new Route(
                'PUT',
                '/notify/delivery',
                fn(Request $request, array $params): array => $this->updateDelivery($request),
                AuthPolicy::Session,
                [LiveUpdateScope::All],
            ),
            new Route(
                'POST',
                '/ping/refresh',
                fn(Request $request, array $params): array => $this->refreshPing($request),
                AuthPolicy::Session,
            ),
        ];
    }

    private function inventory(Request $request): array
    {
        $requested = $request->query['network'] ?? null;
        if ($requested !== null && !is_scalar($requested)) {
            throw new HttpException(400, 'invalid network');
        }
        try {
            $selected = $this->networks->forCidr($requested === null ? null : (string) $requested);
        } catch (NetworkPolicyException $error) {
            throw new HttpException($error->httpStatus, $error->getMessage());
        }
        return [
            'network' => $selected->prefix(),
            'selected_network' => $selected->cidr,
            'dhcp_network' => $this->config->dhcpNetwork->cidr,
            'networks' => $this->networks->descriptors(),
            'hosts' => $this->inventory->forNetwork($selected->cidr),
            'available_tags' => $this->inventory->availableTags(),
            'saved_filters' => $this->inventory->savedFilters(),
        ];
    }

    private function updateDeviceMetadata(Request $request): array
    {
        try {
            return $this->inventory->updateDeviceMetadata($request->body());
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        } catch (RuntimeException $error) {
            throw new HttpException(409, $error->getMessage());
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new HttpException(409, 'container metadata conflicts with an existing device');
            }
            throw $error;
        }
    }

    private function createSavedFilter(Request $request): array
    {
        $body = $request->body();
        try {
            return $this->inventory->createSavedFilter($body['name'] ?? null, $body['tags'] ?? null);
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new HttpException(409, 'saved filter name must be unique');
            }
            throw $error;
        }
    }

    private function updateSavedFilter(int $id, Request $request): array
    {
        $body = $request->body();
        try {
            $filter = $this->inventory->updateSavedFilter($id, $body['name'] ?? null, $body['tags'] ?? null);
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new HttpException(409, 'saved filter name must be unique');
            }
            throw $error;
        }
        if ($filter === false) {
            throw new HttpException(404, 'saved filter not found');
        }
        return $filter;
    }

    private function deleteSavedFilter(int $id): array
    {
        if (!$this->inventory->deleteSavedFilter($id)) {
            throw new HttpException(404, 'saved filter not found');
        }
        return ['deleted' => true];
    }

    private function telegramChats(): array
    {
        try {
            return $this->notifications->refreshTelegramChats();
        } catch (RuntimeException $error) {
            throw new HttpException(502, $error->getMessage());
        }
    }

    private function updateDelivery(Request $request): array
    {
        try {
            return $this->notifications->updateDelivery($request->body());
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        }
    }

    private function refreshPing(Request $request): array
    {
        $requested = $request->body()['network'] ?? null;
        if ($requested !== null && !is_scalar($requested)) {
            throw new HttpException(400, 'invalid network');
        }
        try {
            $selected = $this->networks->forCidr($requested === null ? null : (string) $requested);
            $this->ping->refresh($selected->cidr);
        } catch (NetworkPolicyException $error) {
            throw new HttpException($error->httpStatus, $error->getMessage());
        } catch (RuntimeException $error) {
            throw new HttpException(409, $error->getMessage());
        }
        return ['status' => 'complete', 'network' => $selected->cidr];
    }
}
