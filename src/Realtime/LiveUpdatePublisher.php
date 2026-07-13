<?php

declare(strict_types=1);

namespace FenPing\Realtime;

interface LiveUpdatePublisher
{
    public function publish(LiveUpdateScope ...$scopes): void;
}
