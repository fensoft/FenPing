<?php

declare(strict_types=1);

namespace FenPing\Api;

use RuntimeException;

final class ResponseException extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('response ready');
    }
}
