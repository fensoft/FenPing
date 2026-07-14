<?php

declare(strict_types=1);

namespace FenPing\Netboot;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use OutOfBoundsException;
use PDO;
use RuntimeException;

final readonly class NetbootImageService
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
    ) {
    }

    public function all(): array { return $this->get_netboot_images(); }
    public function find(int $id): array|false { return $this->get_netboot_image($id); }
    public function create(array $file, string $name = ''): array { return $this->create_netboot_image($file, $name); }
    public function delete(int $id): array { return $this->delete_netboot_image($id); }
    public function deleteFile(array $image): void { $this->delete_netboot_image_file($image); }
    public function path(array $image): string { return $this->netboot_image_path($image); }
    public function exists(int $id): bool { return $this->netboot_image_exists($id); }

    public function withHostCount(int $id): array
    {
        $image = $this->find($id);
        if ($image === false) {
            throw new OutOfBoundsException('netboot image not found');
        }
        $statement = $this->database->connection()->prepare('SELECT COUNT(*) FROM ips WHERE netboot_image_id=:id');
        $statement->execute(['id' => $id]);
        $image['hosts'] = (int) $statement->fetchColumn();
        return $image;
    }

public function get_netboot_images() {
  $stmt = $this->database->connection()->prepare("
    SELECT id, name, filename, original_name, size, created_at,
      (SELECT COUNT(*) FROM ips WHERE ips.netboot_image_id=netboot_images.id) AS hosts
    FROM netboot_images
    ORDER BY created_at DESC, id DESC
  ");
  $stmt->execute();

  $images = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row["id"] = (int)$row["id"];
    $row["size"] = (int)$row["size"];
    $row["hosts"] = (int)$row["hosts"];
    $row["url"] = $this->netboot_image_url($row["id"]);
    $images[] = $row;
  }
  return $images;
}

public function create_netboot_image(array $file, string $name = "") {
  if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
    throw new RuntimeException($this->netboot_upload_error((int)($file["error"] ?? UPLOAD_ERR_NO_FILE)));

  $tmp = (string)($file["tmp_name"] ?? "");
  if ($tmp === "" || !is_uploaded_file($tmp))
    throw new RuntimeException("invalid upload");

  $original = basename((string)($file["name"] ?? "netboot.img"));
  $this->validate_netboot_image($tmp, $original);
  $this->ensure_netboot_dir();
  $displayName = trim($name) !== "" ? trim($name) : $original;
  $filename = $this->unique_netboot_filename($original);
  $target = $this->netboot_dir() . "/" . $filename;

  if (!move_uploaded_file($tmp, $target))
    throw new RuntimeException("failed to save upload");

  chmod($target, 0644);
  $stmt = $this->database->connection()->prepare("
    INSERT INTO netboot_images (name, filename, original_name, size)
    VALUES (:name, :filename, :original_name, :size)
  ");
  $stmt->execute(array(
    "name" => $displayName,
    "filename" => $filename,
    "original_name" => $original,
    "size" => (int)($file["size"] ?? filesize($target))
  ));

  return $this->get_netboot_image((int)$this->database->connection()->lastInsertId());
}

public function netboot_allowed_extensions(): array {
  return array('efi', 'kpxe', 'kkpxe', 'kkkpxe', 'pxe', 'lkrn', '0', 'ipxe');
}

public function validate_netboot_image(string $path, string $original): void {
  $extension = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
  $allowed = $this->netboot_allowed_extensions();
  if (!in_array($extension, $allowed, true)) {
    $suffix = $extension === '' ? '(none)' : '.' . $extension;
    throw new RuntimeException(
      "unsupported netboot file extension $suffix; allowed: ." . implode(', .', $allowed)
    );
  }

  if (preg_match('/\.(?:php[0-9]*|phtml|phar)(?:\.|$)/i', $original))
    throw new RuntimeException('executable PHP filenames are not allowed');

  $size = filesize($path);
  if ($size === false || $size < 1)
    throw new RuntimeException('netboot file is empty');

  $valid = false;
  if ($extension === 'efi') {
    $valid = $this->netboot_is_efi($path);
  } elseif ($extension === 'ipxe') {
    $valid = $this->netboot_is_ipxe_script($path);
  } elseif ($extension === '0') {
    $valid = $this->netboot_contains_marker($path, array('PXELINUX'));
  } else {
    $valid = $this->netboot_contains_marker($path, array('iPXE', 'PXELINUX'));
  }

  if (!$valid)
    throw new RuntimeException("file content does not match .$extension netboot format");
}

public function netboot_is_efi(string $path): bool {
  $handle = fopen($path, 'rb');
  if ($handle === false)
    return false;

  try {
    $dosHeader = fread($handle, 64);
    if ($dosHeader === false || strlen($dosHeader) < 64 || substr($dosHeader, 0, 2) !== "MZ")
      return false;

    $offset = unpack('Voffset', substr($dosHeader, 60, 4));
    $peOffset = (int)($offset['offset'] ?? 0);
    $size = filesize($path);
    if ($peOffset < 64 || $size === false || $peOffset > $size - 96)
      return false;
    if (fseek($handle, $peOffset) !== 0)
      return false;

    $peHeader = fread($handle, 96);
    if ($peHeader === false || strlen($peHeader) < 94 || substr($peHeader, 0, 4) !== "PE\0\0")
      return false;

    $magicData = unpack('vmagic', substr($peHeader, 24, 2));
    $subsystemData = unpack('vsubsystem', substr($peHeader, 92, 2));
    $magic = (int)($magicData['magic'] ?? 0);
    $subsystem = (int)($subsystemData['subsystem'] ?? 0);
    return in_array($magic, array(0x10b, 0x20b), true) && $subsystem === 10;
  } finally {
    fclose($handle);
  }
}

public function netboot_is_ipxe_script(string $path): bool {
  $prefix = $this->netboot_read_prefix($path, 4096);
  if ($prefix === null || strpos($prefix, "\0") !== false)
    return false;

  if (str_starts_with($prefix, "\xEF\xBB\xBF"))
    $prefix = substr($prefix, 3);
  return preg_match('/^#!ipxe(?:[ \t]*\r?\n|[ \t]+)/i', $prefix) === 1;
}

public function netboot_contains_marker(string $path, array $markers): bool {
  $prefix = $this->netboot_read_prefix($path, 1024 * 1024);
  if ($prefix === null)
    return false;

  foreach ($markers as $marker) {
    if (strpos($prefix, $marker) !== false)
      return true;
  }
  return false;
}

public function netboot_read_prefix(string $path, int $limit): ?string {
  $handle = fopen($path, 'rb');
  if ($handle === false)
    return null;

  try {
    $contents = fread($handle, $limit);
    return $contents === false ? null : $contents;
  } finally {
    fclose($handle);
  }
}

public function delete_netboot_image(int $id): array {
  $image = $this->get_netboot_image($id);
  if ($image === false)
    throw new OutOfBoundsException("netboot image not found");

  $stmt = $this->database->connection()->prepare("UPDATE ips SET netboot_image_id=NULL WHERE netboot_image_id=:id");
  $stmt->execute(array("id" => $id));

  $stmt = $this->database->connection()->prepare("DELETE FROM netboot_images WHERE id=:id");
  $stmt->execute(array("id" => $id));

  return $image;
}

public function delete_netboot_image_file(array $image): void {
  $path = $this->netboot_image_path($image);
  if (is_file($path))
    @unlink($path);
}

public function get_netboot_image(int $id) {
  $stmt = $this->database->connection()->prepare("SELECT id, name, filename, original_name, size, created_at FROM netboot_images WHERE id=:id");
  $stmt->execute(array("id" => $id));
  $image = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($image === false)
    return false;
  $image["id"] = (int)$image["id"];
  $image["size"] = (int)$image["size"];
  $image["url"] = $this->netboot_image_url($image["id"]);
  return $image;
}

public function netboot_image_url(int $id): string {
  return '/api/netboot/images/' . $id . '/file';
}

public function netboot_image_path(array $image): string {
  return $this->netboot_dir() . '/' . basename((string)($image['filename'] ?? ''));
}

public function netboot_image_exists($id): bool {
  if ($id === null)
    return true;
  return $this->get_netboot_image((int)$id) !== false;
}

public function netboot_dir(): string {
  return $this->config->dataDir . "/netboot";
}

public function ensure_netboot_dir(): void {
  $dir = $this->netboot_dir();
  if (!is_dir($dir) && !mkdir($dir, 0755, true))
    throw new RuntimeException("failed to create netboot directory");
  if (!is_writable($dir))
    throw new RuntimeException("netboot directory is not writable");
}

public function unique_netboot_filename(string $original): string {
  $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($original));
  $safe = trim($safe, '.-');
  if ($safe === '')
    $safe = 'image.bin';

  $prefix = date('YmdHis') . '-' . bin2hex(random_bytes(4));
  return $prefix . '-' . $safe;
}

public function netboot_upload_error(int $code): string {
  $errors = array(
    UPLOAD_ERR_INI_SIZE => "upload is too large",
    UPLOAD_ERR_FORM_SIZE => "upload is too large",
    UPLOAD_ERR_PARTIAL => "upload was incomplete",
    UPLOAD_ERR_NO_FILE => "no file uploaded",
    UPLOAD_ERR_NO_TMP_DIR => "missing upload temp directory",
    UPLOAD_ERR_CANT_WRITE => "failed to write upload",
    UPLOAD_ERR_EXTENSION => "upload blocked by extension"
  );
  return $errors[$code] ?? "upload failed";
}
}
