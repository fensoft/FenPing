<?php

declare(strict_types=1);

namespace FenPing\Vendor;

use FenPing\Database\DatabaseManager;
use FenPing\Oui\OuiRegistryService;
use Throwable;

final class VendorLookup
{
    private array $cache = [];
    private bool $databaseAvailable = true;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly OuiRegistryService $registry,
    ) {
    }

    public function forMac(string $mac): string
    {
        $normalized = $this->registry->normalizeMac($mac);
        if ($normalized === '') {
            return '';
        }
        if (array_key_exists($normalized, $this->cache)) {
            return $this->cache[$normalized];
        }

        $firstOctet = hexdec(substr($normalized, 0, 2));
        if (($firstOctet & 0x02) !== 0) {
            return $this->cache[$normalized] = '';
        }

        if ($this->databaseAvailable) {
            try {
                $vendor = $this->registry->ieeeOuiDatabaseVendor($this->database->connection(), $normalized);
                if ($vendor !== null) {
                    return $this->cache[$normalized] = $vendor;
                }
            } catch (Throwable) {
                $this->databaseAvailable = false;
            }
        }
        return $this->cache[$normalized] = $this->registry->ieeeOuiVendor($normalized);
    }

    /** @return array{key: string, vendor: string}|null */
    public function assignmentForMac(string $mac): ?array
    {
        $normalized = $this->registry->normalizeMac($mac);
        if ($normalized === '' || (hexdec(substr($normalized, 0, 2)) & 0x02) !== 0) {
            return null;
        }
        try {
            $statement = $this->database->connection()->prepare("
                SELECT prefix_length, prefix, vendor
                FROM oui_vendors
                WHERE (prefix_length=9 AND prefix=:prefix9)
                   OR (prefix_length=7 AND prefix=:prefix7)
                   OR (prefix_length=6 AND prefix=:prefix6)
                ORDER BY prefix_length DESC LIMIT 1
            ");
            $statement->execute([
                'prefix9' => substr($normalized, 0, 9),
                'prefix7' => substr($normalized, 0, 7),
                'prefix6' => substr($normalized, 0, 6),
            ]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if ($row === false || trim((string) $row['vendor']) === '') {
                return null;
            }
            return [
                'key' => strtolower(trim((string) $row['vendor'])),
                'vendor' => trim((string) $row['vendor']),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
