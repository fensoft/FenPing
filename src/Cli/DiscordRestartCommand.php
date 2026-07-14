<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Discord\DiscordNotifier;

final readonly class DiscordRestartCommand implements Command
{
    public function __construct(private DiscordNotifier $discord) {}
    public function run(array $arguments): int
    {
        if ($this->discord->sendDiscordRestartNotification()) echo 'discord restart notification sent' . PHP_EOL;
        else echo 'discord restart notification skipped' . PHP_EOL;
        return 0;
    }
}
