<?php

declare(strict_types=1);

namespace FenPing\Host;

use FenPing\Scan\ProfileCatalog;
use InvalidArgumentException;

final readonly class HostMetadataNormalizer
{
    private const ICONS = [
        'desktop', 'laptop', 'mobile', 'printer', 'camera', 'router',
        'server', 'database', 'lightbulb', 'television', 'game-controller', 'home',
    ];

    public function __construct(private ProfileCatalog $profiles)
    {
    }

    public function text(mixed $value, string $label): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("$label must be a string");
        }
        return trim($value);
    }

    public function notes(mixed $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $this->text($value, 'notes'));
    }

    public function icon(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('icon must be a string');
        }
        $icon = trim($value);
        if ($icon === '') {
            return null;
        }
        if (!in_array($icon, self::ICONS, true)) {
            throw new InvalidArgumentException('invalid host icon');
        }
        return $icon;
    }

    public function tags(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('tags must be a list of strings');
        }
        $tags = [];
        $seen = [];
        foreach ($value as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException('tags must be a list of strings');
            }
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $key = strtolower($tag);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $tags[] = $tag;
            }
        }
        usort($tags, static fn(string $left, string $right): int => strcasecmp($left, $right));
        return $tags;
    }

    public function savedFilterName(mixed $value): string
    {
        $name = $this->text($value, 'filter name');
        if ($name === '') {
            throw new InvalidArgumentException('filter name is required');
        }
        return $name;
    }

    public function profile(mixed $value): string
    {
        return $this->profiles->normalizeScheduled($value);
    }

    public function intervalHours(mixed $value): int
    {
        return $this->profiles->normalizeIntervalHours($value);
    }

    public function databaseFlag(mixed $value): ?string
    {
        return $value === true || $value === 1 || $value === '1' ? '1' : null;
    }
}
