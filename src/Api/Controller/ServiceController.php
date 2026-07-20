<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Audit\AuditLogService;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Scan\ResultService;
use FenPing\Service\MonitoredServiceManager;
use InvalidArgumentException;
use OutOfBoundsException;

final readonly class ServiceController implements Controller
{
    public function __construct(
        private ResultService $results,
        private MonitoredServiceManager $services,
        private AuditLogService $audit,
    ) {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/services', fn(Request $request, array $params): array => $this->results->services()),
            new Route('POST', '/services/pins', fn(Request $request, array $params): array => $this->pin($request->body()), AuthPolicy::Session, [LiveUpdateScope::Services]),
            new Route('DELETE', '/services/pins/{id:int}', fn(Request $request, array $params): array => $this->unpin($params['id']), AuthPolicy::Session, [LiveUpdateScope::Services]),
            new Route('POST', '/services/manual', fn(Request $request, array $params): array => $this->create($request->body()), AuthPolicy::Session, [LiveUpdateScope::Services]),
            new Route('PUT', '/services/manual/{id:int}', fn(Request $request, array $params): array => $this->update($params['id'], $request->body()), AuthPolicy::Session, [LiveUpdateScope::Services]),
            new Route('DELETE', '/services/manual/{id:int}', fn(Request $request, array $params): array => $this->delete($params['id']), AuthPolicy::Session, [LiveUpdateScope::Services]),
            new Route('POST', '/services/manual/{id:int}/check', fn(Request $request, array $params): array => $this->check($params['id']), AuthPolicy::Session, [LiveUpdateScope::Services]),
        ];
    }

    private function pin(array $body): array
    {
        return $this->call(function () use ($body): array {
            $record = $this->services->pin($this->results->services()['services'], $body);
            $this->audit->record('service.pinned', 'monitored_service', $record['id'], 'Pinned discovered service', ['after' => $this->snapshot($record)]);
            return ['service' => $record];
        });
    }

    private function unpin(int $id): array
    {
        return $this->call(function () use ($id): array {
            $record = $this->services->unpin($id);
            $this->audit->record('service.unpinned', 'monitored_service', $id, 'Unpinned discovered service', ['before' => $this->snapshot($record)]);
            return ['deleted' => true];
        });
    }

    private function create(array $body): array
    {
        return $this->call(function () use ($body): array {
            $record = $this->services->createManual($body);
            $this->audit->record('service.created', 'monitored_service', $record['id'], 'Created manual service ' . $record['name'], ['after' => $this->snapshot($record)]);
            return ['service' => $record];
        });
    }

    private function update(int $id, array $body): array
    {
        return $this->call(function () use ($id, $body): array {
            $record = $this->services->updateManual($id, $body);
            $this->audit->record('service.updated', 'monitored_service', $id, 'Updated manual service ' . $record['name'], ['after' => $this->snapshot($record)]);
            return ['service' => $record];
        });
    }

    private function delete(int $id): array
    {
        return $this->call(function () use ($id): array {
            $record = $this->services->deleteManual($id);
            $this->audit->record('service.deleted', 'monitored_service', $id, 'Deleted manual service ' . $record['name'], ['before' => $this->snapshot($record)]);
            return ['deleted' => true];
        });
    }

    private function check(int $id): array
    {
        return $this->call(function () use ($id): array {
            $record = $this->services->check($id);
            $this->audit->record('service.checked', 'monitored_service', $id, 'Checked manual service ' . $record['name'], ['status' => $record['check_status']]);
            return ['service' => $record];
        });
    }

    private function call(callable $operation): array
    {
        try {
            return $operation();
        } catch (OutOfBoundsException $error) {
            throw new HttpException(404, $error->getMessage());
        } catch (InvalidArgumentException $error) {
            $status = str_contains($error->getMessage(), 'already') ? 409 : 400;
            throw new HttpException($status, $error->getMessage());
        }
    }

    private function snapshot(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'source', 'type', 'name', 'target', 'port', 'protocol', 'service', 'check_status', 'observed_ip',
        ]));
    }
}
