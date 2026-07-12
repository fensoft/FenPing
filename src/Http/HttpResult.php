<?php

declare(strict_types=1);

namespace FenPing\Http;

final readonly class HttpResult
{
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }
}
