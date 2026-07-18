<?php

declare(strict_types=1);

namespace FenPing\Report;

use FenPing\Database\DatabaseManager;
use InvalidArgumentException;
use PDO;

final readonly class ScheduledReportSettingsRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function get(): array
    {
        $row = $this->database->connection()->query(
            'SELECT daily_enabled, weekly_enabled, hour_utc, weekly_day, certificate_warning_days, updated_at
             FROM scheduled_report_settings WHERE id=1',
        )->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->defaults();
        }
        return [
            'daily_enabled' => (int) $row['daily_enabled'] === 1,
            'weekly_enabled' => (int) $row['weekly_enabled'] === 1,
            'hour_utc' => (int) $row['hour_utc'],
            'weekly_day' => (int) $row['weekly_day'],
            'certificate_warning_days' => (int) $row['certificate_warning_days'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    public function update(array $value): array
    {
        $settings = $this->validate($value);
        $current = $this->get();
        $comparable = $current;
        unset($comparable['updated_at']);
        if ($settings === $comparable) {
            return $current;
        }
        $statement = $this->database->connection()->prepare(
            'INSERT INTO scheduled_report_settings (
               id, daily_enabled, weekly_enabled, hour_utc, weekly_day, certificate_warning_days, updated_at
             ) VALUES (1, :daily, :weekly, :hour, :day, :certificate_days, CURRENT_TIMESTAMP)
             ON CONFLICT(id) DO UPDATE SET
               daily_enabled=excluded.daily_enabled,
               weekly_enabled=excluded.weekly_enabled,
               hour_utc=excluded.hour_utc,
               weekly_day=excluded.weekly_day,
               certificate_warning_days=excluded.certificate_warning_days,
               updated_at=CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'daily' => $settings['daily_enabled'] ? 1 : 0,
            'weekly' => $settings['weekly_enabled'] ? 1 : 0,
            'hour' => $settings['hour_utc'],
            'day' => $settings['weekly_day'],
            'certificate_days' => $settings['certificate_warning_days'],
        ]);
        return $this->get();
    }

    public function validate(array $value): array
    {
        $expected = ['daily_enabled', 'weekly_enabled', 'hour_utc', 'weekly_day', 'certificate_warning_days'];
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || !is_bool($value['daily_enabled'])
            || !is_bool($value['weekly_enabled'])
            || !is_int($value['hour_utc'])
            || !is_int($value['weekly_day'])
            || !is_int($value['certificate_warning_days'])
            || $value['hour_utc'] < 0 || $value['hour_utc'] > 23
            || $value['weekly_day'] < 0 || $value['weekly_day'] > 6
            || $value['certificate_warning_days'] < 1 || $value['certificate_warning_days'] > 365) {
            throw new InvalidArgumentException('invalid scheduled report settings');
        }
        return $value;
    }

    private function defaults(): array
    {
        return [
            'daily_enabled' => false,
            'weekly_enabled' => false,
            'hour_utc' => 8,
            'weekly_day' => 1,
            'certificate_warning_days' => 30,
            'updated_at' => '',
        ];
    }
}
