<?php

declare(strict_types=1);

namespace FenPing\Api;

final class JsonResponse extends Response
{
    public function __construct(mixed $data, int $status = 200)
    {
        parent::__construct(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            (string) json_encode($data),
        );
    }
}
