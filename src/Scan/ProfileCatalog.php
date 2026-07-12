<?php

declare(strict_types=1);

namespace FenPing\Scan;

use InvalidArgumentException;

final class ProfileCatalog
{
    public const MANAGED_DEFAULT = 'standard';
    public const MANAGED_INTERVAL_HOURS = 24;
    public const UNMANAGED_DEFAULT = 'lightweight';
    public const UNMANAGED_INTERVAL_HOURS = 24;

    public function all(): array
    {
        return [
            ['id' => 'lightweight', 'name' => 'Lightweight', 'description' => 'Fast check of the 100 most common TCP ports with basic service names.', 'timeout_seconds' => 300],
            ['id' => 'standard', 'name' => 'Standard', 'description' => 'Top 1,000 TCP ports with service, OS, script, and traceroute detection.', 'timeout_seconds' => 1800],
            ['id' => 'deep', 'name' => 'Deep', 'description' => 'All 65,535 TCP ports with service, OS, script, and traceroute detection.', 'timeout_seconds' => 7200],
        ];
    }

    public function ids(bool $includeLegacy = true): array
    {
        $ids = array_column($this->all(), 'id');
        if ($includeLegacy) {
            array_unshift($ids, 'quick');
        }
        return $ids;
    }

    public function isValid(string $profile, bool $includeLegacy = true): bool
    {
        return in_array($profile, $this->ids($includeLegacy), true);
    }

    public function rank(string $profile): int
    {
        return match ($profile) {
            'quick', 'lightweight' => 1,
            'standard' => 2,
            'deep' => 3,
            default => 0,
        };
    }

    public function isPartial(string $profile): bool
    {
        return $this->rank($profile) > 0 && $profile !== 'deep';
    }

    public function timeout(string $profile): int
    {
        $profile = $profile === 'quick' ? 'lightweight' : $profile;
        foreach ($this->all() as $definition) {
            if ($definition['id'] === $profile) {
                return (int) $definition['timeout_seconds'];
            }
        }
        throw new InvalidArgumentException('invalid scan profile');
    }

    public function normalizeScheduled(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('invalid scan profile');
        }
        $profile = strtolower(trim((string) $value));
        if (!$this->isValid($profile, false)) {
            throw new InvalidArgumentException('invalid scan profile');
        }
        return $profile;
    }

    public function normalizeIntervalHours(mixed $value): int
    {
        if (is_int($value)) {
            $hours = $value;
        } elseif (is_string($value) && ctype_digit(trim($value))) {
            $hours = (int) trim($value);
        } else {
            throw new InvalidArgumentException('invalid scan cadence');
        }
        if ($hours < 0 || $hours > 8760) {
            throw new InvalidArgumentException('scan cadence must be between 0 and 8760 hours');
        }
        return $hours;
    }
}
