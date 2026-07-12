<?php

declare(strict_types=1);

namespace FenPing\Http;

use FenPing\Backend\Backend;

final class NativeHttpClient implements HttpClient
{
    public function __construct(private readonly Backend $backend) {}

    public function request(string $url, array $options = []): HttpResult
    {
        $result = $this->backend->fenpingHttpRequest($url, $options);
        return new HttpResult((int) $result['status'], $result['headers'], (string) $result['body']);
    }
}
