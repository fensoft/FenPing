<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Api\JsonResponse;
use FenPing\Api\Request;
use FenPing\Api\Response;
use FenPing\Api\Route;
use FenPing\Audit\AuditLogService;
use FenPing\Inventory\InventoryWorkerLauncher;
use FenPing\Network\NetworkManager;
use FenPing\Network\NetworkPolicyException;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Scan\ProfileCatalog;
use FenPing\Scan\ResultService;
use FenPing\Scan\ScanJobRepository;
use OutOfBoundsException;
use RuntimeException;

final readonly class ScanController implements Controller
{
    public function __construct(
        private ScanJobRepository $jobs,
        private ProfileCatalog $profiles,
        private ResultService $results,
        private NetworkManager $networks,
        private InventoryWorkerLauncher $worker,
        private AuditLogService $audit,
    ) {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/scans', fn(Request $request, array $params): array => [
                'scans' => $this->jobs->queue(),
                'policy' => $this->jobs->policySummary(),
            ]),
            new Route('GET', '/scans/profiles', fn(Request $request, array $params): array => ['profiles' => $this->profiles->all()]),
            new Route('GET', '/services', fn(Request $request, array $params): array => $this->results->services()),
            new Route(
                'POST',
                '/scans/{ip:ipv4}',
                fn(Request $request, array $params): JsonResponse => $this->queue($params['ip'], $this->profile($request)),
                AuthPolicy::Session,
                [LiveUpdateScope::Scans],
            ),
            new Route(
                'POST',
                '/scans/{ip:ipv4}/quick',
                fn(Request $request, array $params): JsonResponse => $this->queue($params['ip'], 'lightweight'),
                AuthPolicy::Session,
                [LiveUpdateScope::Scans],
            ),
            new Route('GET', '/scans/{ip:ipv4}/status', fn(Request $request, array $params): array => $this->status($params['ip'], $request)),
            new Route(
                'POST',
                '/scans/{ip:ipv4}/{id:int}/cancel',
                fn(Request $request, array $params): JsonResponse => $this->cancel($params['ip'], $params['id']),
                AuthPolicy::Session,
            ),
            new Route('GET', '/scans/{ip:ipv4}/history', fn(Request $request, array $params): array => $this->jobs->history($params['ip'])),
            new Route('GET', '/scans/{ip:ipv4}/history/{id:int}', fn(Request $request, array $params): array => $this->results->forHost($params['ip'], $params['id'])),
            new Route('GET', '/scans/{ip:ipv4}/history/{id:int}/xml', fn(Request $request, array $params): Response => $this->results->xml($params['ip'], $params['id'])),
            new Route('GET', '/scans/{ip:ipv4}/xml', fn(Request $request, array $params): Response => $this->results->xml($params['ip'])),
            new Route('GET', '/scans/{ip:ipv4}', fn(Request $request, array $params): array => $this->results->forHost($params['ip'])),
            new Route('GET', '/scans/{file:scanXml}', fn(Request $request, array $params): Response => $this->results->xml($this->ipFromFile($params['file']))),
            new Route('GET', '/scans/{ip:ipv4}/{id:int}', fn(Request $request, array $params): array => $this->results->forHost($params['ip'], $params['id'])),
            new Route('GET', '/scans/{ip:ipv4}/{file:scanIdXml}', fn(Request $request, array $params): Response => $this->results->xml($params['ip'], $this->idFromFile($params['file']))),
        ];
    }

    private function profile(Request $request): string
    {
        $profile = $request->body()['profile'] ?? '';
        if (!is_string($profile) || !$this->profiles->isValid($profile, false)) {
            throw new HttpException(400, 'invalid scan profile');
        }
        return $profile;
    }

    private function queue(string $ip, string $profile): JsonResponse
    {
        try {
            $this->networks->forIp($ip);
        } catch (NetworkPolicyException $error) {
            throw new HttpException($error->httpStatus, $error->getMessage());
        }
        $queued = $this->jobs->enqueue($ip, $profile);
        $this->worker->start();
        $metadata = $queued['metadata'];
        $this->audit->record(
            'scan.queued', 'scan', $metadata['id'] ?? null,
            ($queued['created'] ? 'Queued' : 'Reused') . " manual {$profile} scan for {$ip}",
            ['ip' => $ip, 'profile' => $profile, 'created' => $queued['created']],
        );
        return new JsonResponse([
            'queued' => true,
            'created' => $queued['created'],
            'profile' => $profile,
            'metadata' => $queued['metadata'],
            'xml' => '/api/scans/' . rawurlencode($ip) . '/xml',
        ], 202);
    }

    private function cancel(string $ip, int $id): JsonResponse
    {
        try {
            $result = $this->jobs->cancel($ip, $id);
        } catch (OutOfBoundsException $error) {
            throw new HttpException(404, $error->getMessage());
        } catch (RuntimeException $error) {
            throw new HttpException(409, $error->getMessage());
        }
        $metadata = $result['metadata'];
        $this->audit->record(
            'scan.cancelled', 'scan', $metadata['id'] ?? $id, "Cancelled manual scan for {$ip}",
            ['ip' => $ip, 'profile' => $metadata['mode'] ?? null, 'state' => $metadata['state'] ?? null],
        );
        return new JsonResponse([
            'cancellation_requested' => true,
            'cancelled' => $metadata['state'] === 'cancelled',
            'metadata' => $metadata,
        ], $result['status']);
    }

    private function status(string $ip, Request $request): array
    {
        $requestedId = $request->query['id'] ?? null;
        if ($requestedId !== null) {
            if (!is_scalar($requestedId) || !ctype_digit((string) $requestedId)) {
                throw new HttpException(400, 'invalid scan id');
            }
            $metadata = $this->jobs->byId($ip, (int) $requestedId);
            if ($metadata === null) {
                throw new HttpException(404, 'scan not found');
            }
            return $metadata;
        }
        $parts = explode('.', $ip);
        return $this->jobs->latest($ip) ?: [
            'ip' => $ip,
            'network' => implode('.', array_slice($parts, 0, 3)) . '.0/24',
            'request_source' => 'legacy',
            'state' => 'none',
            'ports_count' => 0,
            'progress_percent' => 0,
            'progress_phase' => 'none',
            'progress_updated_at' => null,
            'queue_position' => null,
            'queue_reason' => null,
            'budget_eligible_at' => null,
            'cancel_requested' => false,
        ];
    }

    private function ipFromFile(string $file): string
    {
        return substr($file, 0, -4);
    }

    private function idFromFile(string $file): int
    {
        return (int) substr($file, 0, -4);
    }
}
