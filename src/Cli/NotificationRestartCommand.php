<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Status\NotificationService;

final readonly class NotificationRestartCommand implements Command
{
    public function __construct(private NotificationService $notifications) {}
    public function run(array $arguments): int
    {
        $results = $this->notifications->sendRestartNotification();
        foreach ($results as $provider => $sent) {
            if ($sent === null) echo $provider . ' restart notification skipped' . PHP_EOL;
            elseif ($sent) echo $provider . ' restart notification sent' . PHP_EOL;
            else echo $provider . ' restart notification failed' . PHP_EOL;
        }
        return in_array(false, $results, true) ? 1 : 0;
    }
}
