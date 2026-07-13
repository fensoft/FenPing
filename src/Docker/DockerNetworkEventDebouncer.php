<?php

declare(strict_types=1);

namespace FenPing\Docker;

final class DockerNetworkEventDebouncer
{
    private ?float $deadline = null;

    public function __construct(private readonly float $delaySeconds = 1.0)
    {
    }

    public function mark(float $now): void
    {
        $this->deadline = $now + $this->delaySeconds;
    }

    public function due(float $now): bool
    {
        return $this->deadline !== null && $now >= $this->deadline;
    }

    public function consume(): bool
    {
        if ($this->deadline === null) {
            return false;
        }
        $this->deadline = null;
        return true;
    }

    public function remainingMicroseconds(float $now): int
    {
        if ($this->deadline === null) {
            return 1_000_000;
        }
        return max(0, (int) ceil(($this->deadline - $now) * 1_000_000));
    }
}
