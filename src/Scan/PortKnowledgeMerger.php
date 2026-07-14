<?php

declare(strict_types=1);

namespace FenPing\Scan;

final readonly class PortKnowledgeMerger
{
    public function merge(?array $known, array $observed): array
    {
        if ($known === null) {
            return $observed;
        }
        foreach (['service', 'details', 'tunnel', 'product', 'version', 'extra_info', 'method', 'os_type'] as $field) {
            if (trim((string) ($observed[$field] ?? '')) === '') {
                $observed[$field] = $known[$field] ?? '';
            }
        }
        if (($observed['confidence'] ?? null) === null) {
            $observed['confidence'] = $known['confidence'] ?? null;
        }
        if (($observed['cpes'] ?? []) === []) {
            $observed['cpes'] = $known['cpes'] ?? [];
        }
        return $observed;
    }
}
