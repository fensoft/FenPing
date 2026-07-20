<?php

declare(strict_types=1);

namespace FenPing\Service;

use FenPing\Status\NotificationService;
use InvalidArgumentException;
use Throwable;

final readonly class MonitoredServiceManager
{
    public function __construct(
        private MonitoredServiceRepository $repository,
        private ServiceProbe $probe,
        private NotificationService $notifications,
    ) {
    }

    public function merge(array $payload): array
    {
        $services = array_values($payload['services'] ?? []);
        $byKey = [];
        foreach ($services as $index => $service) {
            $byKey[$this->key($service)] = $index;
        }

        $important = [];
        $manualCount = 0;
        foreach ($this->repository->all() as $record) {
            if ($record['source'] === 'manual') {
                $manualCount++;
                $important[] = [
                    ...$record,
                    'origin' => 'manual',
                    'available' => $record['check_status'] === 'healthy',
                ];
                continue;
            }

            $key = $this->key([
                'ip' => $record['target'],
                'protocol' => $record['protocol'],
                'port' => $record['port'],
            ]);
            if (isset($byKey[$key])) {
                $index = $byKey[$key];
                $services[$index]['important'] = true;
                $services[$index]['monitored_service_id'] = $record['id'];
                $current = $services[$index];
                $important[] = [
                    ...$current,
                    'id' => $record['id'],
                    'monitored_service_id' => $record['id'],
                    'origin' => 'discovered',
                    'important' => true,
                    'available' => true,
                    'last_seen_at' => $current['scan_date'] ?? $record['last_seen_at'],
                    'check_status' => null,
                    'check_detail' => null,
                ];
                continue;
            }
            $important[] = [
                'id' => $record['id'],
                'monitored_service_id' => $record['id'],
                'origin' => 'discovered',
                'important' => true,
                'available' => false,
                'name' => $record['name'],
                'ip' => $record['target'],
                'protocol' => $record['protocol'],
                'port' => $record['port'],
                'service' => $record['service'],
                'version' => $record['version'],
                'tunnel' => $record['tunnel'],
                'last_seen_at' => $record['last_seen_at'],
                'check_status' => null,
                'check_detail' => null,
                'last_checked_at' => null,
            ];
        }

        usort($important, static function (array $left, array $right): int {
            $health = static fn(array $row): int => ($row['available'] ?? false) ? 1 : 0;
            return $health($left) <=> $health($right)
                ?: strcasecmp((string) ($left['name'] ?? $left['ip'] ?? ''), (string) ($right['name'] ?? $right['ip'] ?? ''));
        });

        $payload['services'] = $services;
        $payload['important_services'] = $important;
        $payload['summary'] = [
            ...($payload['summary'] ?? []),
            'important' => count($important),
            'manual' => $manualCount,
        ];
        return $payload;
    }

    public function pin(array $discoveredServices, array $input): array
    {
        $ip = trim((string) ($input['ip'] ?? ''));
        $protocol = strtolower(trim((string) ($input['protocol'] ?? '')));
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        foreach ($discoveredServices as $service) {
            if ((string) ($service['ip'] ?? '') === $ip
                && strtolower((string) ($service['protocol'] ?? '')) === $protocol
                && (int) ($service['port'] ?? 0) === $port) {
                return $this->repository->pin($service);
            }
        }
        throw new InvalidArgumentException('discovered service is not available');
    }

    public function unpin(int $id): array
    {
        return $this->repository->delete($id, 'discovered');
    }

    public function createManual(array $input): array
    {
        return $this->check((int) $this->repository->createManual($input)['id']);
    }

    public function updateManual(int $id, array $input): array
    {
        $this->repository->updateManual($id, $input);
        return $this->check($id);
    }

    public function deleteManual(int $id): array
    {
        return $this->repository->delete($id, 'manual');
    }

    public function check(int $id): array
    {
        $record = $this->repository->find($id);
        if ($record['source'] !== 'manual') {
            throw new InvalidArgumentException('only manual services can be checked');
        }
        $change = $this->repository->recordCheck($id, $this->probe->check($record));
        $previous = (string) ($change['before']['check_status'] ?? '');
        $current = (string) ($change['after']['check_status'] ?? '');
        if (in_array($previous, ['healthy', 'unhealthy'], true) && $previous !== $current) {
            try {
                $this->notifications->sendManualServiceChange([
                    ...$change['after'],
                    'previous_status' => $previous,
                    'status' => $current,
                    'important' => 1,
                    'occurred_at' => $change['after']['last_checked_at'],
                ]);
            } catch (Throwable $error) {
                error_log('manual service notification failed: ' . $error->getMessage());
            }
        }
        return $change['after'];
    }

    public function checkAll(): array
    {
        $checked = [];
        foreach ($this->repository->all() as $record) {
            if ($record['source'] === 'manual') {
                $checked[] = $this->check((int) $record['id']);
            }
        }
        return $checked;
    }

    private function key(array $service): string
    {
        return strtolower((string) ($service['ip'] ?? $service['target'] ?? '')) . '|'
            . strtolower((string) ($service['protocol'] ?? '')) . '|'
            . (int) ($service['port'] ?? 0);
    }
}
