<?php

declare(strict_types=1);

namespace FenPing\Host;

use FenPing\Database\DatabaseManager;
use FenPing\Scan\ProfileCatalog;
use PDO;

final readonly class HostRepository
{
    public function __construct(
        private DatabaseManager $database,
        private HostMetadataRepository $metadata,
    ) {
    }

    public function byId(int $id): array|false
    {
        return $this->one('SELECT * FROM ips WHERE id=:value', $id);
    }

    public function byIp(string $ip): array|false
    {
        return $this->one('SELECT * FROM ips WHERE ip=:value', $ip);
    }

    public function byMac(string $mac): array|false
    {
        return $this->one('SELECT * FROM ips WHERE LOWER(mac)=:value', strtolower($mac));
    }

    public function create(string $ip, string $mac): int
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ips (mac, ip, scan_profile, scan_interval_hours)
             VALUES (:mac, :ip, :scan_profile, :scan_interval_hours)',
        );
        $statement->execute([
            'mac' => $mac,
            'ip' => $ip,
            'scan_profile' => ProfileCatalog::MANAGED_DEFAULT,
            'scan_interval_hours' => ProfileCatalog::MANAGED_INTERVAL_HOURS,
        ]);
        return (int) $this->database->connection()->lastInsertId();
    }

    public function update(
        int $id,
        ?string $ip,
        string $mac,
        string $name,
        mixed $repeater,
        mixed $important,
        mixed $web,
        ?string $router,
        ?string $dns,
        ?int $netbootImageId = null,
        string $profile = ProfileCatalog::MANAGED_DEFAULT,
        int $intervalHours = ProfileCatalog::MANAGED_INTERVAL_HOURS,
        string $notes = '',
        string $location = '',
        string $owner = '',
        string $model = '',
        ?string $icon = null,
        array $tags = [],
        string $displayName = '',
    ): int {
        $statement = $this->database->connection()->prepare("
            UPDATE ips SET
              name=:name, mac=:mac, ip=:ip, repeater=:repeater, important=:important,
              web=:web, router=:router, dns=:dns, netboot_image_id=:netboot_image_id,
              scan_profile=:scan_profile, scan_interval_hours=:scan_interval_hours,
              display_name=:display_name, notes=:notes, location=:location,
              owner=:owner, model=:model, icon=:icon
            WHERE id=:id
        ");
        $statement->execute([
            'name' => $name,
            'mac' => $mac,
            'ip' => $ip,
            'repeater' => $repeater == '1' ? '1' : null,
            'important' => $important == '1' ? '1' : null,
            'web' => $web == '1' ? '1' : null,
            'router' => $router === '' ? null : $router,
            'dns' => $dns === '' ? null : $dns,
            'netboot_image_id' => $netbootImageId,
            'scan_profile' => $profile,
            'scan_interval_hours' => $intervalHours,
            'display_name' => $displayName === '' ? null : $displayName,
            'notes' => $notes === '' ? null : $notes,
            'location' => $location === '' ? null : $location,
            'owner' => $owner === '' ? null : $owner,
            'model' => $model === '' ? null : $model,
            'icon' => $icon,
            'id' => $id,
        ]);
        $this->metadata->replaceHostTags($id, $tags);
        return $statement->rowCount();
    }

    public function delete(int $id): int
    {
        $statement = $this->database->connection()->prepare('DELETE FROM ips WHERE id=:id');
        $statement->execute(['id' => $id]);
        return $statement->rowCount();
    }

    private function one(string $sql, int|string $value): array|false
    {
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute(['value' => $value]);
        $host = $statement->fetch(PDO::FETCH_ASSOC);
        return $host === false ? false : $this->metadata->normalizeManagedHostMetadata($host);
    }
}
