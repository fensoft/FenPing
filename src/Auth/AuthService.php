<?php

declare(strict_types=1);

namespace FenPing\Auth;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Config\AppConfig;

final readonly class AuthService
{
    private const SESSION_NAME = 'FenPingSession';

    public function __construct(private AppConfig $config)
    {
    }

    public function session(): array
    {
        return [
            'authenticated' => $this->isAuthenticated(),
            'configured' => $this->config->password !== '',
        ];
    }

    public function authorize(AuthPolicy $policy, array $body): void
    {
        if ($policy === AuthPolicy::Guest || $this->isAuthenticated()) {
            return;
        }
        if ($policy === AuthPolicy::BodyOrSession
            && array_key_exists('password', $body)
            && $this->login($body['password'])) {
            return;
        }
        throw new HttpException(403, 'login required');
    }

    public function login(mixed $password): bool
    {
        if (!hash_equals($this->config->password, (string) $password)) {
            return false;
        }
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION['fenping_authenticated'] = true;
        $_SESSION['fenping_secret'] = $this->sessionSecret();
        return true;
    }

    public function logout(): void
    {
        $this->startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            $options = [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ];
            if (($params['domain'] ?? '') !== '') {
                $options['domain'] = $params['domain'];
            }
            setcookie(session_name(), '', $options);
        }
        session_destroy();
    }

    public function isAuthenticated(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE[self::SESSION_NAME])) {
            return false;
        }
        $this->startSession();
        return !empty($_SESSION['fenping_authenticated'])
            && hash_equals($this->sessionSecret(), (string) ($_SESSION['fenping_secret'] ?? ''));
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    private function sessionSecret(): string
    {
        return hash('sha256', $this->config->secret . '|' . $this->config->password);
    }

    private function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
