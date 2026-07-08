<?php

function hostsApiRoutes(): array {
  return array(
    apiRoute('GET', '/history/{ip:ipv4}', 'handleHostHistory'),
    apiRoute('GET', '/hosts/{id:int}', 'handleHostGet'),
    apiRoute('POST', '/hosts', 'handleHostCreate', 'body'),
    apiRoute('PUT', '/hosts/{id:int}', 'handleHostEdit', 'body'),
    apiRoute('DELETE', '/hosts/{id:int}', 'handleHostDelete', 'body'),
    apiRoute('POST', '/categories', 'handleCategoryCreate', 'body'),
    apiRoute('DELETE', '/categories', 'handleCategoryDelete', 'body')
  );
}

function handleHostHistory(array $params): array {
  return get_history_response($params['ip']);
}

function handleHostGet(array $params): array {
  $host = getId($params['id']);
  if ($host === false)
    jsonError(404, 'host not found');
  return $host;
}

function handleHostCreate(array $params): array {
  $body = requestBody();
  $id = create(normalizeHostIp($body['ip'] ?? null), $body['mac'] ?? '');
  return array('id' => (int)$id);
}

function handleHostEdit(array $params): array {
  $body = requestBody();

  edit(
    $params['id'],
    normalizeHostIp($body['ip'] ?? null),
    $body['mac'] ?? '',
    $body['name'] ?? '',
    toDbFlag($body['repeater'] ?? null),
    toDbFlag($body['important'] ?? null),
    toDbFlag($body['web'] ?? null),
    normalizeEmpty($body['router'] ?? null),
    normalizeEmpty($body['dns'] ?? null),
    normalizeNetbootImageId($body['netboot_image_id'] ?? null)
  );

  return array('log' => reloadDhcpHosts());
}

function handleHostDelete(array $params): array {
  del($params['id']);
  return array('deleted' => true);
}

function handleCategoryCreate(array $params): array {
  $body = requestBody();
  addCategory($body['ip'] ?? '', $body['name'] ?? '');
  return array('created' => true);
}

function handleCategoryDelete(array $params): array {
  $body = requestBody();
  delCategory($body['ip'] ?? '');
  return array('deleted' => true);
}
