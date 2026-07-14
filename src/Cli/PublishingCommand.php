<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Realtime\LiveUpdatePublisher;

final readonly class PublishingCommand implements Command
{
    public function __construct(private Command $command, private LiveUpdatePublisher $updates, private array $scopes) {}

    public function run(array $arguments): int
    {
        $code = $this->command->run($arguments);
        if ($code === 0) $this->updates->publish(...$this->scopes);
        return $code;
    }
}
