<?php

declare(strict_types=1);

namespace FenPing\Service;

final readonly class ServiceProbeResult
{
    public function __construct(
        public bool $healthy,
        public string $detail,
        public ?string $observedIp = null,
    ) {
    }
}
