<?php

function netbootApiRoutes(): array {
  return array(
    apiRoute('GET', '/netboot/images', 'handleNetbootImagesList'),
    apiRoute('POST', '/netboot/images', 'handleNetbootImageCreate', 'session'),
    apiRoute('GET', '/netboot/images/{id:int}', 'handleNetbootImageGet'),
    apiRoute('GET', '/netboot/images/{id:int}/file', 'handleNetbootImageFile'),
    apiRoute('DELETE', '/netboot/images/{id:int}', 'handleNetbootImageDelete', 'session')
  );
}

function handleNetbootImagesList(array $params): array {
  return array('images' => get_netboot_images());
}

function handleNetbootImageGet(array $params): array {
  return netbootImageWithHostCount($params['id']);
}

function handleNetbootImageFile(array $params): void {
  $image = get_netboot_image($params['id']);
  if ($image === false)
    jsonError(404, 'netboot image not found');

  $path = netboot_image_path($image);
  if (!is_file($path) || !is_readable($path))
    jsonError(404, 'netboot file not found');

  $downloadName = basename((string)($image['original_name'] ?: $image['filename']));
  $fallbackName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName);
  if ($fallbackName === '')
    $fallbackName = 'netboot.bin';

  header('Content-Type: application/octet-stream');
  header('X-Content-Type-Options: nosniff');
  header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
  header('Content-Length: ' . filesize($path));
  readfile($path);
  exit;
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
    $change = commitDhcpMutation(fn() => delete_netboot_image($params['id']));
  } catch (OutOfBoundsException $e) {
    jsonError(404, $e->getMessage());
  }

  delete_netboot_image_file($change['result']);
  return array('deleted' => true, 'log' => $change['log']);
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
