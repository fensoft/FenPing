<?php

declare(strict_types=1);

namespace FenPing\Health;

use FenPing\Database\DatabaseManager;
use PDO;
use Throwable;

final readonly class DatabaseHealthProbe
{
    public function __construct(private DatabaseManager $database)
    {
    }

public function healthDb(): array {
  try {
    $time = $this->database->connection()->query("SELECT CURRENT_TIMESTAMP")->fetchColumn();
    return array(
      'ok' => true,
      'engine' => 'sqlite',
      'time' => $time === false ? null : $time
    );
  } catch (Throwable $e) {
    return array(
      'ok' => false,
      'error' => 'connection failed'
    );
  }
}

public function healthLastPingScan(): ?array {
  try {
    $time = $this->healthFetchValue("SELECT MAX(date) FROM ping");
    return $this->healthTimeResult($time);
  } catch (Throwable $e) {
    return null;
  }
}

public function healthLastInventoryScan(): ?array {
  try {
    $stmt = $this->database->connection()->query("
      SELECT id, ip, mode, state, status, date_begin, date_end,
             COALESCE(date_end, date_begin) AS scan_time
      FROM scans
      ORDER BY COALESCE(date_end, date_begin) DESC, id DESC
      LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false)
      return null;

    $result = $this->healthTimeResult($row['scan_time'] ?? null);
    if ($result === null)
      return null;

    $result['scan'] = array(
      'id' => (int)$row['id'],
      'ip' => $row['ip'],
      'mode' => $row['mode'],
      'state' => $row['state'],
      'status' => $row['status'],
      'date_begin' => $row['date_begin'],
      'date_end' => $row['date_end']
    );
    return $result;
  } catch (Throwable $e) {
    return null;
  }
}

public function healthFetchValue(string $sql) {
  $value = $this->database->connection()->query($sql)->fetchColumn();
  return $value === false ? null : $value;
}

public function healthTimeResult($time): ?array {
  if ($time === null || $time === '')
    return null;

  $timestamp = strtotime((string)$time);
  return array(
    'time' => (string)$time,
    'age_seconds' => $timestamp === false ? null : max(0, time() - $timestamp)
  );
}

}
