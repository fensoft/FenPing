<?php

declare(strict_types=1);

namespace FenPing\Netboot;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;

final readonly class NetbootImageService
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database)
    {
    }

    public function all(): array { return $this->backend->get_netboot_images(); }
    public function find(int $id): array|false { return $this->backend->get_netboot_image($id); }
    public function create(array $file, string $name = ''): array { return $this->backend->create_netboot_image($file, $name); }
    public function delete(int $id): array { return $this->backend->delete_netboot_image($id); }
    public function deleteFile(array $image): void { $this->backend->delete_netboot_image_file($image); }
    public function path(array $image): string { return $this->backend->netboot_image_path($image); }
    public function exists(int $id): bool { return $this->backend->netboot_image_exists($id); }
}
