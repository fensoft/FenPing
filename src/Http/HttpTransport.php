<?php

declare(strict_types=1);

namespace FenPing\Http;

interface HttpTransport
{
    /** @return array{status: int, headers: array, body: string} */
    public function request(string $url, array $options = []): array;
}
