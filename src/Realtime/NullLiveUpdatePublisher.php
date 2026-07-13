<?php

declare(strict_types=1);

namespace FenPing\Realtime;

final readonly class NullLiveUpdatePublisher implements LiveUpdatePublisher
{
    public function publish(LiveUpdateScope ...$scopes): void
    {
    }
}
