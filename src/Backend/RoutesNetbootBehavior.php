<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use FenPing\Realtime\LiveUpdateScope;

trait RoutesNetbootBehavior
{
public function netbootApiRoutes(): array {
  return array(
    $this->apiRoute('GET', '/netboot/images', 'handleNetbootImagesList'),
    $this->apiRoute('POST', '/netboot/images', 'handleNetbootImageCreate', 'session', array('live' => array(LiveUpdateScope::Netboot))),
    $this->apiRoute('GET', '/netboot/images/{id:int}', 'handleNetbootImageGet'),
    $this->apiRoute('GET', '/netboot/images/{id:int}/file', 'handleNetbootImageFile'),
    $this->apiRoute('DELETE', '/netboot/images/{id:int}', 'handleNetbootImageDelete', 'session', array('live' => array(LiveUpdateScope::Netboot, LiveUpdateScope::Hosts)))
  );
}

public function handleNetbootImagesList(array $params): array {
  return array('images' => $this->get_netboot_images());
}

public function handleNetbootImageGet(array $params): array {
  return $this->netbootImageWithHostCount($params['id']);
}

public function handleNetbootImageFile(array $params): void {
  $image = $this->get_netboot_image($params['id']);
  if ($image === false)
    $this->jsonError(404, 'netboot image not found');

  $path = $this->netboot_image_path($image);
  if (!is_file($path) || !is_readable($path))
    $this->jsonError(404, 'netboot file not found');

  $downloadName = basename((string)($image['original_name'] ?: $image['filename']));
  throw new \FenPing\Api\ResponseException(new \FenPing\Api\FileResponse(
    $path,
    $downloadName
  ));
}

public function handleNetbootImageCreate(array $params): array {
  try {
    return $this->create_netboot_image($_FILES['file'] ?? array(), $_POST['name'] ?? '');
  } catch (RuntimeException $e) {
    $this->jsonError(400, $e->getMessage());
  }
}

public function handleNetbootImageDelete(array $params): array {
  try {
    $change = $this->commitDhcpMutation(fn() => $this->delete_netboot_image($params['id']));
  } catch (OutOfBoundsException $e) {
    $this->jsonError(404, $e->getMessage());
  }

  $this->delete_netboot_image_file($change['result']);
  return array('deleted' => true, 'log' => $change['log']);
}

public function netbootImageWithHostCount(int $id): array {
  $image = $this->get_netboot_image($id);
  if ($image === false)
    $this->jsonError(404, 'netboot image not found');

  $stmt = $this->getDb()->prepare("SELECT COUNT(*) FROM ips WHERE netboot_image_id=:id");
  $stmt->execute(array('id' => $id));
  $image['hosts'] = (int)$stmt->fetchColumn();
  return $image;
}
}
