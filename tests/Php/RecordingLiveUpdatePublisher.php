<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;

final class RecordingLiveUpdatePublisher implements LiveUpdatePublisher
{
    /** @var list<list<LiveUpdateScope>> */
    public array $events = [];

    public function publish(LiveUpdateScope ...$scopes): void
    {
        $this->events[] = $scopes;
    }
}
