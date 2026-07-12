<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use Closure;
use FenPing\Api\AuthPolicy;
use FenPing\Api\Route;

final class RouteAdapter
{
    /** @return list<Route> */
    public function adapt(array $routes): array
    {
        return array_map(
            static fn(array $route): Route => new Route(
                method: (string) $route['method'],
                pattern: (string) $route['pattern'],
                handler: Closure::fromCallable($route['handler']),
                auth: AuthPolicy::fromLegacy($route['auth'] ?? false),
            ),
            $routes,
        );
    }
}
