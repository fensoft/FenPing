<?php

declare(strict_types=1);

namespace FenPing\Inventory;

use FenPing\Dhcp\HostValidator;
use FenPing\Host\HostMetadataRepository;
use InvalidArgumentException;

final readonly class BulkInventoryTargetNormalizer
{
    public function __construct(
        private HostMetadataRepository $metadata,
        private HostValidator $validator,
    ) {
    }

    public function normalize(mixed $value, string $action): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('targets must be a non-empty list');
        }
        $targets = [];
        foreach ($value as $target) {
            if (!is_array($target)) {
                throw new InvalidArgumentException('invalid inventory target');
            }
            $normalized = $this->target($target);
            $key = $action === 'approve' && isset($normalized['mac'])
                ? 'mac:' . $normalized['mac']
                : $this->key($normalized);
            $targets[$key] = $normalized;
        }
        return array_values($targets);
    }

    private function target(array $target): array
    {
        $kind = $target['kind'] ?? null;
        if ($kind === 'host') {
            $id = $target['id'] ?? null;
            if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1) {
                throw new InvalidArgumentException('invalid host target');
            }
            return ['kind' => 'host', 'id' => (int) $id];
        }
        if ($kind === 'device') {
            $normalized = [
                'kind' => 'device',
                'network' => $this->metadata->normalizeInventoryDeviceIdentityPart($target['network'] ?? null, 'network name'),
                'container' => $this->metadata->normalizeInventoryDeviceIdentityPart($target['container'] ?? null, 'container name'),
            ];
            $mac = $this->optionalMac($target['mac'] ?? null);
            if ($mac !== '') {
                $normalized['mac'] = $mac;
            }
            return $normalized;
        }
        if ($kind === 'observed') {
            $mac = $this->optionalMac($target['mac'] ?? null);
            $ip = $this->optionalIp($target['ip'] ?? null);
            if ($mac === '' && $ip === '') {
                throw new InvalidArgumentException('observed target requires a MAC or IP address');
            }
            return array_filter(
                ['kind' => 'observed', 'mac' => $mac, 'ip' => $ip],
                static fn(string $item): bool => $item !== '',
            );
        }
        throw new InvalidArgumentException('invalid inventory target kind');
    }

    private function optionalMac(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return $this->validator->mac($value, false);
    }

    private function optionalIp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return (string) $this->validator->ip($value, false);
    }

    private function key(array $target): string
    {
        return match ($target['kind']) {
            'host' => 'host:' . $target['id'],
            'device' => "device:{$target['network']}\0{$target['container']}",
            'observed' => isset($target['mac']) ? 'observed:mac:' . $target['mac'] : 'observed:ip:' . $target['ip'],
        };
    }
}
