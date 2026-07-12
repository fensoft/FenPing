<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Backend\Backend;

use FenPing\Auth\AuthService;

final readonly class AuthController implements Controller
{
    public function __construct(private Backend $backend, private AuthService $auth, private RouteAdapter $adapter)
    {
    }

    public function routes(): array
    {
        return $this->adapter->adapt($this->backend->authApiRoutes());
    }
}
