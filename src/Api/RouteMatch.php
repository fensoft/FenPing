<?php

declare(strict_types=1);

namespace FenPing\Api;

final readonly class RouteMatch
{
    public function __construct(public Route $route, public array $params)
    {
    }
}
