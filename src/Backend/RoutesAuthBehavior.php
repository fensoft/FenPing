<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;

trait RoutesAuthBehavior
{
public function authApiRoutes(): array {
  return array(
    $this->apiRoute('GET', '/auth/session', 'handleAuthSession'),
    $this->apiRoute('POST', '/auth/login', 'handleAuthLogin'),
    $this->apiRoute('POST', '/auth/logout', 'handleAuthLogout')
  );
}

public function handleAuthSession(array $params): array {
  return $this->authSession();
}

public function handleAuthLogin(array $params): array {
  $body = $this->requestBody();
  if (!$this->authLogin($body['password'] ?? ''))
    $this->jsonError(403, 'wrong password');
  return $this->authSession();
}

public function handleAuthLogout(array $params): array {
  $this->authLogout();
  return $this->authGuestSession();
}
}
