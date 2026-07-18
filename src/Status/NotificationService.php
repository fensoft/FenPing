<?php

declare(strict_types=1);

namespace FenPing\Status;

use FenPing\Config\AppConfig;
use FenPing\Discord\DiscordNotifier;
use FenPing\Report\ScheduledReportService;
use InvalidArgumentException;

final readonly class NotificationService
{
    public function __construct(
        private AppConfig $config,
        private NotificationQueryRepository $queries,
        private NotificationRuleRepository $rules,
        private DiscordNotifier $discord,
        private TelegramNotifier $telegram,
        private TelegramChatRepository $chats,
        private ScheduledReportService $reports,
    ) {
    }

    public function recent(int $hours = 24): array { return $this->queries->get_notify($hours, $this->delivery()); }
    public function portChanges(int $hours = 24): array { return $this->queries->get_port_notify($hours); }
    public function notifyPortChangesForScan(int $id): void {
        if (!$this->providersEnabled()) return;
        $changes = $this->discord->discordPortChangesForScan($id);
        $this->discord->sendDiscordPortChanges($changes);
        $this->telegram->sendTelegramServiceChanges($changes);
    }
    public function delivery(): array {
        return [
            'rules' => $this->rules->notificationRules(),
            'discord' => [
                'configured' => $this->discord->discordNotificationsEnabled(),
                'mention_target' => $this->discordMentionTarget(),
            ],
            'telegram' => [
                'configured' => $this->chats->telegramBotConfigured(),
                'chat_selected' => $this->chats->telegramSelectedChatId() !== null,
            ],
            'reports' => $this->reports->deliverySettings(),
        ];
    }
    public function updateRules(array $rules): array
    {
        $this->rules->notificationRulesUpdate($rules);
        return $this->delivery();
    }
    public function refreshTelegramChats(): array { return $this->chats->telegramRefreshKnownChats(); }

    public function updateDelivery(array $body): array
    {
        $updateTelegramChat = array_key_exists('telegram_chat_id', $body);
        $updateReports = array_key_exists('reports', $body);
        $expected = ['rules'];
        if ($updateTelegramChat) $expected[] = 'telegram_chat_id';
        if ($updateReports) $expected[] = 'reports';
        $this->rules->notificationRequireExactKeys($body, $expected);
        if (!is_array($body['rules'] ?? null)) {
            throw new InvalidArgumentException('invalid notification rules');
        }
        $rules = $this->rules->notificationValidateRules($body['rules']);
        $reports = null;
        if ($updateReports) {
            if (!is_array($body['reports'])) {
                throw new InvalidArgumentException('invalid scheduled report settings');
            }
            $reports = $this->reports->validateSettings($body['reports']);
        }
        if ($updateTelegramChat) {
            $this->chats->telegramChatSelectionUpdate($body['telegram_chat_id']);
        }
        $this->rules->notificationRulesUpdate($rules);
        if ($reports !== null) $this->reports->updateSettings($reports);
        return $this->delivery();
    }

    public function runScheduledReports(): array { return $this->reports->runDue(); }

    public function providersEnabled(): bool {
        return $this->discord->discordNotificationsEnabled() || $this->chats->telegramNotificationsEnabled();
    }

    public function statusChangesEnabled(): bool {
        if (!$this->providersEnabled()) return false;
        $rules = $this->rules->notificationRules()['host_status'];
        return $rules['normal'] || $rules['important'];
    }

    public function sendStatusChangesSince(?int $afterId): void {
        if ($afterId === null || !$this->providersEnabled()) return;
        $changes = $this->discord->discordStatusChangesSince($afterId);
        $this->discord->sendDiscordStatusChanges($changes);
        $this->telegram->sendTelegramStatusChanges($changes);
    }

    public function sendIpConflictChanges(array $changes): void {
        $this->discord->sendDiscordIpConflictChanges($changes);
        $this->telegram->sendTelegramIpConflictChanges($changes);
    }

    public function sendRestartNotification(): array {
        if (!$this->rules->notificationRules()['restart']) return ['discord' => null, 'telegram' => null];
        return [
            'discord' => $this->discord->discordNotificationsEnabled() ? $this->discord->sendDiscordRestartNotification() : null,
            'telegram' => $this->chats->telegramNotificationsEnabled() ? $this->telegram->sendTelegramRestartNotification() : null,
        ];
    }

    private function discordMentionTarget(): ?string {
        if ($this->config->discordMention === '') return null;
        return $this->config->discordMention === '@everyone' ? 'everyone' : 'user';
    }
}
