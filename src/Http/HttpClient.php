<?php

declare(strict_types=1);

namespace FenPing\Http;

interface HttpClient
{
    public function request(string $url, array $options = []): HttpResult;
}
