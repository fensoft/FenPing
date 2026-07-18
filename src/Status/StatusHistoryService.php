<?php

declare(strict_types=1);

namespace FenPing\Status;

use FenPing\Database\DatabaseManager;
use FenPing\Support\Clock;
use PDO;

final readonly class StatusHistoryService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {
    }

    public function response(string $ip): array
    {
        $rows = $this->history($ip);
        return ['summary' => $this->summary($rows), 'rows' => $rows];
    }

    public function history(string $ip, int $blipSeconds = 120): array { return $this->get_history($ip, $blipSeconds); }
    public function summary(array $rows): array { return $this->get_history_summary($rows); }
    public function mergeBlips(array $rows, int $seconds): array { return $this->merge_history_blips($rows, $seconds); }

    public function statsMap(): array
    {
        $statement = $this->database->connection()->query("
            SELECT
              *,
              unixepoch(date_begin) AS `begin`,
              unixepoch(date_end) AS `end`,
              unixepoch(date_end)-unixepoch(date_begin) AS duration
            FROM stats INDEXED BY stats_date_end
            WHERE ip IS NOT NULL AND ip<>''
              AND date_end > datetime('now', '-7 days')
            ORDER BY ip, id ASC
        ");
        $rowsByIp = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $rowsByIp[(string) $row['ip']][] = $row;
        }
        $stats = [];
        foreach ($rowsByIp as $ip => $rows) {
            $summary = $this->summary($this->normalizeHistoryRows($rows, 120));
            if (!$summary['stable']) $stats[$ip] = $summary;
        }
        return $stats;
    }

public function get_history($ip, $blipSeconds = 120) {
  $stmt = $this->database->connection()->prepare("select *, unixepoch(date_begin) as `begin`, unixepoch(date_end) as `end`, unixepoch(date_end)-unixepoch(date_begin) as duration from stats where ip=:ip and date_end > datetime('now', '-7 days') order by id asc");
  $stmt->execute(array("ip" => $ip));
  return $this->normalizeHistoryRows($stmt->fetchAll(PDO::FETCH_ASSOC), $blipSeconds);
}

private function normalizeHistoryRows(array $before, int $blipSeconds): array {
  $cutoff = $this->clock->now()->getTimestamp() - 7 * 24 * 60 * 60;
  if (count($before) > 0) {
    $now = $this->clock->now()->getTimestamp();
    $lastIndex = count($before) - 1;
    $currentStatus = (string)($before[$lastIndex]["status"] ?? "");
    $currentBegin = (int)($before[$lastIndex]["begin"] ?? $now);
    for ($index = $lastIndex - 1; $index >= 0; $index--) {
      if ((string)($before[$index]["status"] ?? "") !== $currentStatus)
        break;
      $currentBegin = min($currentBegin, (int)($before[$index]["begin"] ?? $currentBegin));
    }
    $before[$lastIndex]["end"] = $now;
    $before[$lastIndex]["date_end"] = date("Y-m-d H:i:s", $now);
    $before[$lastIndex]["duration"] = max(0, $now - (int)$before[$lastIndex]["begin"]);
    $before[$lastIndex]["actual_current_seconds"] = max(0, $now - $currentBegin);
    $before[$lastIndex]["current"] = 1;
  }
  foreach ($before as &$row) {
    if ((int)$row["begin"] < $cutoff) {
      $row["begin"] = $cutoff;
      $row["date_begin"] = date("Y-m-d H:i:s", $cutoff);
    }
    $row["duration"] = max(0, (int)$row["end"] - (int)$row["begin"]);
  }
  unset($row);

  $after = array();
  $lastIndex = count($before) - 1;
  foreach ($before as $index => $i) {
    $rowIndex = count($after)-1;
    if ($rowIndex >= 0 && $after[$rowIndex]["status"] == $i["status"]) {
      $after[$rowIndex]["date_end"] = $i["date_end"];
      $after[$rowIndex]["end"] = $i["end"];
      $after[$rowIndex]["duration"] += $i["duration"];
      if (!empty($i["current"]))
        $after[$rowIndex]["current"] = 1;
      if (isset($i["actual_current_seconds"]))
        $after[$rowIndex]["actual_current_seconds"] = $i["actual_current_seconds"];
    } else {
      array_push($after, $i);
    }
  }
  return $this->merge_history_blips($after, $blipSeconds);
}

public function merge_history_blips($rows, $blipSeconds) {
  $changed = true;
  while ($changed) {
    $changed = false;
    $count = count($rows);
    for ($i = 0; $i < $count; $i++) {
      if (!empty($rows[$i]["current"]) || (int)$rows[$i]["duration"] >= $blipSeconds)
        continue;

      if ($i > 0 && $i + 1 < $count && $rows[$i - 1]["status"] == $rows[$i + 1]["status"]) {
        $rows[$i - 1]["date_end"] = $rows[$i + 1]["date_end"];
        $rows[$i - 1]["end"] = $rows[$i + 1]["end"];
        $rows[$i - 1]["duration"] += $rows[$i]["duration"] + $rows[$i + 1]["duration"];
        if (!empty($rows[$i + 1]["current"]))
          $rows[$i - 1]["current"] = 1;
        array_splice($rows, $i, 2);
        $changed = true;
        break;
      }

      if ($i > 0) {
        $rows[$i - 1]["date_end"] = $rows[$i]["date_end"];
        $rows[$i - 1]["end"] = $rows[$i]["end"];
        $rows[$i - 1]["duration"] += $rows[$i]["duration"];
        array_splice($rows, $i, 1);
        $changed = true;
        break;
      }

      if ($i + 1 < $count) {
        $rows[$i + 1]["date_begin"] = $rows[$i]["date_begin"];
        $rows[$i + 1]["begin"] = $rows[$i]["begin"];
        $rows[$i + 1]["duration"] += $rows[$i]["duration"];
        array_splice($rows, $i, 1);
        $changed = true;
        break;
      }
    }
  }
  return array_values($rows);
}

public function get_history_summary($rows) {
  $observed = 0;
  $up = 0;
  $longestDown = 0;

  foreach ($rows as $row) {
    $duration = max(0, (int)($row["duration"] ?? 0));
    $observed += $duration;
    if (($row["status"] ?? "") == "Up")
      $up += $duration;
    else
      $longestDown = max($longestDown, $duration);
  }

  $transitions = max(0, count($rows) - 1);
  $uptime = $observed > 0 ? round(($up / $observed) * 100, 1) : 0;
  $current = count($rows) > 0 ? $rows[count($rows) - 1] : null;
  $stable = count($rows) <= 1 && $current !== null && ($current["status"] ?? "") == "Up" && $uptime >= 99.95;

  return array(
    "uptime_percent" => $uptime,
    "observed_seconds" => $observed,
    "up_seconds" => $up,
    "transitions" => $transitions,
    "longest_down_seconds" => $longestDown,
    "current_status" => $current["status"] ?? "",
    "current_seconds" => $current === null ? 0 : max(0, (int)($current["actual_current_seconds"] ?? $current["duration"] ?? 0)),
    "stable" => $stable,
    "level" => $this->stability_level($uptime, $transitions, $longestDown, $current["status"] ?? ""),
    "label" => $this->stability_label($uptime)
  );
}

public function stability_label($uptime) {
  return (int)round($uptime) . "%";
}

public function stability_level($uptime, $transitions, $longestDown, $currentStatus) {
  if ($currentStatus != "Up")
    return "bad";
  if ($uptime >= 99 && $transitions <= 1)
    return "good";
  if ($uptime >= 95 && $transitions <= 4 && $longestDown < 60 * 60)
    return "warn";
  return "bad";
}
}
