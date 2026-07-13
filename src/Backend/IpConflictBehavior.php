<?php

declare(strict_types=1);

namespace FenPing\Backend;

trait IpConflictBehavior
{
    public function getIpConflictStatus(?string $network = null): array
    {
        $monitor = $this->ipConflictMonitorStatus($network);
        return [
            'network' => $network ?? $this->config->dhcpNetwork->cidr,
            'status' => $monitor['status'],
            'monitors' => $monitor['monitors'],
            'conflicts' => array_map($this->enrichIpConflict(...), $this->ipConflicts->active($network)),
        ];
    }

    public function getIpConflictChanges(int $hours = 24): array
    {
        return array_map($this->enrichIpConflict(...), $this->ipConflicts->recent($hours));
    }

    public function ipConflictTransitionDetails(array $transitions): array
    {
        return array_map($this->enrichIpConflict(...), $this->ipConflicts->transitionDetails($transitions));
    }

    public function ipConflictMonitorStatus(?string $network = null): array
    {
        $stored = [];
        foreach ($this->ipConflicts->monitors() as $monitor) {
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

    private function enrichIpConflict(array $conflict): array
    {
        $devices = [];
        foreach ($conflict['devices'] ?? [] as $device) {
            $device['vendor'] = $this->getVendor((string) ($device['mac'] ?? ''));
            $devices[] = $device;
        }
        $conflict['devices'] = $devices;
        return $conflict;
    }
}
