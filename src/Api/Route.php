<?php

declare(strict_types=1);

namespace FenPing\Api;

use Closure;
use FenPing\Realtime\LiveUpdateScope;

final readonly class Route
{
    /** @param Closure(Request, array): mixed $handler */
    public function __construct(
        public string $method,
        public string $pattern,
        public Closure $handler,
        public AuthPolicy $auth = AuthPolicy::Guest,
        /** @var list<LiveUpdateScope> */
        public array $liveScopes = [],
    ) {
    }
}
