<?php

function netbootApiRoutes(): array {
  return array(
    apiRoute('GET', '/netboot/images', 'handleNetbootImagesList'),
    apiRoute('POST', '/netboot/images', 'handleNetbootImageCreate', 'session'),
    apiRoute('GET', '/netboot/images/{id:int}', 'handleNetbootImageGet'),
    apiRoute('DELETE', '/netboot/images/{id:int}', 'handleNetbootImageDelete', 'session')
  );
}

function handleNetbootImagesList(array $params): array {
  return array('images' => get_netboot_images());
}

function handleNetbootImageGet(array $params): array {
  return netbootImageWithHostCount($params['id']);
}

function handleNetbootImageCreate(array $params): array {
  try {
    return create_netboot_image($_FILES['file'] ?? array(), $_POST['name'] ?? '');
  } catch (RuntimeException $e) {
    jsonError(400, $e->getMessage());
  }
}

function handleNetbootImageDelete(array $params): array {
  try {
    delete_netboot_image($params['id']);
  } catch (InvalidArgumentException $e) {
    jsonError(404, $e->getMessage());
  }

  return array('deleted' => true, 'log' => reloadDhcpHosts());
}

function netbootImageWithHostCount(int $id): array {
  $image = get_netboot_image($id);
  if ($image === false)
    jsonError(404, 'netboot image not found');

  $stmt = getDb()->prepare("SELECT COUNT(*) FROM ips WHERE netboot_image_id=:id");
  $stmt->execute(array('id' => $id));
  $image['hosts'] = (int)$stmt->fetchColumn();
  return $image;
}
