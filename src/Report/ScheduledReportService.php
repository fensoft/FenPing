<?php

declare(strict_types=1);

namespace FenPing\Report;

use DateTimeImmutable;
use DateTimeZone;
use FenPing\Database\DatabaseManager;
use FenPing\Discord\DiscordNotifier;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Status\TelegramChatRepository;
use FenPing\Status\TelegramNotifier;
use FenPing\Support\Clock;
use PDO;
use Throwable;

final readonly class ScheduledReportService
{
    public function __construct(
        private DatabaseManager $database,
        private ScheduledReportSettingsRepository $settings,
        private ScheduledReportQueryRepository $queries,
        private ScheduledReportFormatter $formatter,
        private DiscordNotifier $discord,
        private TelegramNotifier $telegram,
        private TelegramChatRepository $chats,
        private Clock $clock,
        private LiveUpdatePublisher $liveUpdates,
    ) {}

    public function deliverySettings(): array
    {
        $settings = $this->settings->get();
        unset($settings['updated_at']);
        return ['settings' => $settings, 'last_runs' => $this->lastRuns()];
    }

    public function updateSettings(array $settings): array
    {
        $saved = $this->settings->update($settings);
        unset($saved['updated_at']);
        return $saved;
    }

    public function validateSettings(array $settings): array
    {
        return $this->settings->validate($settings);
    }

    public function runDue(): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $settings = $this->settings->get();
        $results = [];
        foreach (['daily', 'weekly'] as $frequency) {
            $slot = $this->slot($frequency, $now, $settings);
            if ($slot === null || new DateTimeImmutable($settings['updated_at'], new DateTimeZone('UTC')) > $slot) {
                $results[$frequency] = 'not_due';
                continue;
            }
            $results[$frequency] = $this->run($frequency, $slot, $settings);
        }
        return $results;
    }

    public function preview(string $frequency): array
    {
        if (!in_array($frequency, ['daily', 'weekly'], true)) {
            throw new \InvalidArgumentException('invalid report frequency');
        }
        $end = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $settings = $this->settings->get();
        return $this->queries->summary(
            $end->modify($frequency === 'daily' ? '-1 day' : '-7 days'),
            $end,
            $settings['certificate_warning_days'],
        );
    }

    private function run(string $frequency, DateTimeImmutable $slot, array $settings): string
    {
        $periodKey = $slot->format('Y-m-d');
        $start = $slot->modify($frequency === 'daily' ? '-1 day' : '-7 days');
        $statement = $this->database->connection()->prepare(
            "INSERT OR IGNORE INTO scheduled_report_runs
             (frequency, period_key, scheduled_for, window_start, window_end, state)
             VALUES (:frequency, :period, :scheduled, :start, :end, 'running')",
        );
        $statement->execute([
            'frequency' => $frequency,
            'period' => $periodKey,
            'scheduled' => $slot->format('Y-m-d H:i:s'),
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $slot->format('Y-m-d H:i:s'),
        ]);
        if ($statement->rowCount() === 0) {
            return 'already_run';
        }
        $id = (int) $this->database->connection()->lastInsertId();
        try {
            $report = $this->queries->summary($start, $slot, $settings['certificate_warning_days']);
            $attempted = false;
            $success = true;
            if ($this->discord->discordNotificationsEnabled()) {
                $attempted = true;
                $success = $this->discord->discordPostPayload($this->formatter->discord($report, $frequency)) && $success;
            }
            if ($this->chats->telegramNotificationsEnabled()) {
                $attempted = true;
                $success = $this->telegram->telegramPostText($this->formatter->telegram($report, $frequency)) && $success;
            }
            $state = !$attempted ? 'skipped' : ($success ? 'success' : 'failure');
            $error = !$attempted ? 'no notification provider is configured' : ($success ? null : 'one or more providers failed');
            $this->finish($id, $state, $report['counts'], $error);
            $this->liveUpdates->publish(LiveUpdateScope::Operations);
            return $state;
        } catch (Throwable $error) {
            $this->finish($id, 'failure', null, $error->getMessage());
            $this->liveUpdates->publish(LiveUpdateScope::Operations);
            return 'failure';
        }
    }

    private function slot(string $frequency, DateTimeImmutable $now, array $settings): ?DateTimeImmutable
    {
        if (!$settings[$frequency . '_enabled']) {
            return null;
        }
        if ($frequency === 'daily') {
            $slot = $now->setTime($settings['hour_utc'], 0);
            return $now >= $slot ? $slot : null;
        }
        $monday = $now->modify('monday this week')->setTime($settings['hour_utc'], 0);
        $offset = $settings['weekly_day'] === 0 ? 6 : $settings['weekly_day'] - 1;
        $slot = $monday->modify('+' . $offset . ' days');
        return $now >= $slot ? $slot : null;
    }

    private function finish(int $id, string $state, ?array $counts, ?string $error): void
    {
        $statement = $this->database->connection()->prepare(
            'UPDATE scheduled_report_runs SET state=:state, summary_json=:summary, error=:error,
             finished_at=CURRENT_TIMESTAMP WHERE id=:id',
        );
        $statement->execute([
            'state' => $state,
            'summary' => $counts === null ? null : json_encode($counts, JSON_THROW_ON_ERROR),
            'error' => $error,
            'id' => $id,
        ]);
    }

    private function lastRuns(): array
    {
        $rows = $this->database->connection()->query(
            "SELECT frequency, state, scheduled_for, finished_at, summary_json, error
             FROM scheduled_report_runs ORDER BY id DESC",
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $frequency = (string) $row['frequency'];
            if (isset($result[$frequency])) {
                continue;
            }
            $row['summary'] = $row['summary_json'] === null ? null : json_decode((string) $row['summary_json'], true);
            unset($row['summary_json'], $row['frequency']);
            $result[$frequency] = $row;
        }
        return $result;
    }
}
