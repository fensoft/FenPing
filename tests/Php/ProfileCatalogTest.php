<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Scan\ProfileCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProfileCatalogTest extends TestCase
{
    public function testProfilesAndRanksRemainCompatible(): void
    {
        $profiles = new ProfileCatalog();
        self::assertSame(['lightweight', 'standard', 'deep'], array_column($profiles->all(), 'id'));
        self::assertSame(1, $profiles->rank('quick'));
        self::assertSame(3, $profiles->rank('deep'));
        self::assertTrue($profiles->isPartial('standard'));
        self::assertFalse($profiles->isPartial('deep'));
        self::assertSame(7200, $profiles->timeout('deep'));
    }

    public function testScheduledInputsAreValidated(): void
    {
        $profiles = new ProfileCatalog();
        self::assertSame('standard', $profiles->normalizeScheduled(' Standard '));
        self::assertSame(24, $profiles->normalizeIntervalHours('24'));

        $this->expectException(InvalidArgumentException::class);
        $profiles->normalizeIntervalHours(8761);
    }
}
