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
}
