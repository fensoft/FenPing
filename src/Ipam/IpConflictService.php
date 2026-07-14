<?php

declare(strict_types=1);

namespace FenPing\Ipam;

use FenPing\Config\AppConfig;
use FenPing\Network\NetworkManager;
use FenPing\Vendor\VendorLookup;

final readonly class IpConflictService
{
    public function __construct(
        private AppConfig $config,
        private IpConflictRepository $repository,
        private NetworkManager $networks,
        private VendorLookup $vendors,
    ) {
    }

    public function status(?string $network = null): array
    {
        $monitor = $this->monitorStatus($network);
        return [
            'network' => $network ?? $this->config->dhcpNetwork->cidr,
            'status' => $monitor['status'],
            'monitors' => $monitor['monitors'],
            'conflicts' => array_map($this->enrich(...), $this->repository->active($network)),
        ];
    }

    public function recent(int $hours = 24): array
    {
        return array_map($this->enrich(...), $this->repository->recent($hours));
    }

    public function transitionDetails(array $transitions): array
    {
        return array_map($this->enrich(...), $this->repository->transitionDetails($transitions));
    }

    public function monitorStatus(?string $network = null): array
    {
        $stored = [];
        foreach ($this->repository->monitors() as $monitor) {
            $stored[$monitor['network']] = $monitor;
        }
        $monitors = [];
        foreach ($this->networks->configured() as $configured) {
            if ($network !== null && $configured->cidr !== $network) {
                continue;
            }
            $monitors[] = $stored[$configured->cidr] ?? [
                'network' => $configured->cidr,
                'status' => 'pending',
                'last_attempt_at' => null,
                'last_success_at' => null,
                'last_error_at' => null,
            ];
        }
        $status = 'pending';
        if (array_filter($monitors, static fn(array $item): bool => $item['status'] === 'degraded') !== []) {
            $status = 'degraded';
        } elseif (array_filter($monitors, static fn(array $item): bool => $item['status'] === 'ok') !== []) {
            $status = 'ok';
        }
        return ['status' => $status, 'monitors' => $monitors];
    }

    private function enrich(array $conflict): array
    {
        $devices = [];
        foreach ($conflict['devices'] ?? [] as $device) {
            $device['vendor'] = $this->vendors->forMac((string) ($device['mac'] ?? ''));
            $devices[] = $device;
        }
        $conflict['devices'] = $devices;
        return $conflict;
    }
}
