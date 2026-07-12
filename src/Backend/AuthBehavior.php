<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

trait AuthBehavior
{
public const AUTH_SESSION_NAME = 'FenPingSession';

public function authSession(): array {
  return array(
    'authenticated' => $this->authIsAuthenticated(),
    'configured' => (string)($this->config->password) !== ''
  );
}

public function authGuestSession(): array {
  return array(
    'authenticated' => false,
    'configured' => (string)($this->config->password) !== ''
  );
}

public function authLogin($password): bool {
  $expected = (string)($this->config->password);
  $given = (string)$password;

  if (!hash_equals($expected, $given))
    return false;

  $this->authStartSession();
  session_regenerate_id(true);
  $_SESSION['fenping_authenticated'] = true;
  $_SESSION['fenping_secret'] = $this->authSessionSecret();
  return true;
}

public function authLogout(): void {
  $this->authStartSession();
  $_SESSION = array();

  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $options = array(
      'expires' => time() - 42000,
      'path' => $params['path'] ?: '/',
      'secure' => (bool)$params['secure'],
      'httponly' => (bool)$params['httponly'],
      'samesite' => $params['samesite'] ?? 'Lax'
    );
    if (($params['domain'] ?? '') !== '')
      $options['domain'] = $params['domain'];
    setcookie(session_name(), '', $options);
  }

  session_destroy();
}

public function authIsAuthenticated(): bool {
  if (session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE[self::AUTH_SESSION_NAME]))
    return false;

  $this->authStartSession();
  return !empty($_SESSION['fenping_authenticated'])
    && hash_equals($this->authSessionSecret(), (string)($_SESSION['fenping_secret'] ?? ''));
}

public function authStartSession(): void {
  if (session_status() === PHP_SESSION_ACTIVE)
    return;

  session_name(self::AUTH_SESSION_NAME);
  session_set_cookie_params(array(
    'lifetime' => 0,
    'path' => '/',
    'secure' => $this->authIsHttps(),
    'httponly' => true,
    'samesite' => 'Lax'
  ));
  session_start();
}

public function authSessionSecret(): string {
  return hash('sha256', (string)($this->config->secret) . '|' . (string)($this->config->password));
}

public function authIsHttps(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    return true;
  return (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
}
