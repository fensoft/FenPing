<?php

declare(strict_types=1);

namespace FenPing\Discord;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Http\HttpClient;

final readonly class DiscordNotifier
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database, private HttpClient $http)
    {
    }

    public function enabled(): bool { return $this->config->discordWebhookUrl !== ''; }
    public function restart(): bool { return $this->backend->sendDiscordRestartNotification(); }
    public function statusChangesSince(?int $id): void { $this->backend->sendDiscordStatusChangesSince($id); }
    public function portChangesForScan(int $id): void { $this->backend->sendDiscordPortChangesForScan($id); }
}
