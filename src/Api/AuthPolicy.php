<?php

declare(strict_types=1);

namespace FenPing\Api;

enum AuthPolicy: string
{
    case Guest = 'guest';
    case Session = 'session';
    case BodyOrSession = 'body';

    public static function fromLegacy(false|string $value): self
    {
        return match ($value) {
            'session' => self::Session,
            'body' => self::BodyOrSession,
            default => self::Guest,
        };
    }
}
