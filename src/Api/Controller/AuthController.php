<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Auth\AuthService;

final readonly class AuthController implements Controller
{
    public function __construct(private AuthService $auth)
    {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/auth/session', fn(Request $request, array $params): array => $this->auth->session()),
            new Route('POST', '/auth/login', function (Request $request, array $params): array {
                if (!$this->auth->login($request->body()['password'] ?? '')) {
                    throw new HttpException(403, 'wrong password');
                }
                return $this->auth->session();
            }),
            new Route('POST', '/auth/logout', function (Request $request, array $params): array {
                $this->auth->logout();
                return $this->auth->session();
            }),
        ];
    }
}
