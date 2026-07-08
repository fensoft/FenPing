<?php

function authApiRoutes(): array {
  return array(
    apiRoute('GET', '/auth/session', 'handleAuthSession'),
    apiRoute('POST', '/auth/login', 'handleAuthLogin'),
    apiRoute('POST', '/auth/logout', 'handleAuthLogout')
  );
}

function handleAuthSession(array $params): array {
  return authSession();
}

function handleAuthLogin(array $params): array {
  $body = requestBody();
  if (!authLogin($body['password'] ?? ''))
    jsonError(403, 'wrong password');
  return authSession();
}

function handleAuthLogout(array $params): array {
  authLogout();
  return authGuestSession();
}
