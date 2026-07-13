<?php

declare(strict_types=1);

namespace FenPing\Doctor;

use JsonSerializable;

final readonly class DoctorReport implements JsonSerializable
{
    /** @param list<DoctorCheck> $checks */
    public function __construct(public string $checkedAt, public array $checks)
    {
    }

    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->passed) {
                return false;
            }
        }
        return true;
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->passed() ? 'ok' : 'failed',
            'checked_at' => $this->checkedAt,
            'checks' => $this->checks,
        ];
    }
}
