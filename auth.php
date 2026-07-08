<?php

const AUTH_SESSION_NAME = 'FenPingSession';

function authSession(): array {
  return array(
    'authenticated' => authIsAuthenticated(),
    'configured' => (string)($GLOBALS['password'] ?? '') !== ''
  );
}

function authGuestSession(): array {
  return array(
    'authenticated' => false,
    'configured' => (string)($GLOBALS['password'] ?? '') !== ''
  );
}

function authLogin($password): bool {
  $expected = (string)($GLOBALS['password'] ?? '');
  $given = (string)$password;

  if (!hash_equals($expected, $given))
    return false;

  authStartSession();
  session_regenerate_id(true);
  $_SESSION['fenping_authenticated'] = true;
  $_SESSION['fenping_secret'] = authSessionSecret();
  return true;
}

function authLogout(): void {
  authStartSession();
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

function authIsAuthenticated(): bool {
  if (session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE[AUTH_SESSION_NAME]))
    return false;

  authStartSession();
  return !empty($_SESSION['fenping_authenticated'])
    && hash_equals(authSessionSecret(), (string)($_SESSION['fenping_secret'] ?? ''));
}

function authStartSession(): void {
  if (session_status() === PHP_SESSION_ACTIVE)
    return;

  session_name(AUTH_SESSION_NAME);
  session_set_cookie_params(array(
    'lifetime' => 0,
    'path' => '/',
    'secure' => authIsHttps(),
    'httponly' => true,
    'samesite' => 'Lax'
  ));
  session_start();
}

function authSessionSecret(): string {
  return hash('sha256', (string)($GLOBALS['secret'] ?? '') . '|' . (string)($GLOBALS['password'] ?? ''));
}

function authIsHttps(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    return true;
  return (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
