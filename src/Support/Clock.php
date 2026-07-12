<?php

declare(strict_types=1);

namespace FenPing\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
