<?php

function discordWebhookUrl(): string {
  $url = getenv('DISCORD_WEBHOOK_URL');
  return $url === false ? '' : trim($url);
}

function discordNotificationsEnabled(): bool {
  return discordWebhookUrl() !== '';
}

function statsMaxId(): int {
  $value = db()->query("SELECT COALESCE(MAX(id), 0) FROM stats")->fetchColumn();
  return (int)$value;
}

function sendDiscordStatusChangesSince(?int $afterId): void {
  if ($afterId === null || !discordNotificationsEnabled())
    return;

  $changes = discordStatusChangesSince($afterId);
  if (count($changes) === 0)
    return;

  foreach (discordNotificationMessages($changes) as $message)
    discordPost($message);
}

function sendDiscordRestartNotification(): bool {
  if (!discordNotificationsEnabled())
    return false;

  return discordPost(discordRestartMessage());
}

function discordRestartMessage(): string {
  global $network, $myself;

  $host = gethostname();
  if ($host === false || $host === '')
    $host = 'fenping';

  $lines = array(
    'FenPing restarted',
    '- Time: ' . date('Y-m-d H:i:s T'),
    '- Host: ' . $host
  );

  if (($network ?? '') !== '')
    $lines[] = '- Network: ' . $network . '.0/24';
  if (($myself ?? '') !== '')
    $lines[] = '- Address: ' . $myself;

  return implode(PHP_EOL, $lines);
}

function discordStatusChangesSince(int $afterId): array {
  $stmt = db()->prepare("
    SELECT
      s.id,
      s.ip,
      s.mac,
      s.status,
      s.date_begin,
      (SELECT prev.status FROM stats prev WHERE prev.ip=s.ip AND prev.id<s.id ORDER BY prev.id DESC LIMIT 1) AS previous_status,
      COALESCE((
        SELECT i.name
        FROM ips i
        WHERE i.ip=s.ip OR LOWER(i.mac) COLLATE latin1_general_ci=LOWER(s.mac) COLLATE latin1_general_ci
        ORDER BY IF(i.ip=s.ip, 0, 1), i.id DESC
        LIMIT 1
      ), '') AS name,
      COALESCE((
        SELECT i.important
        FROM ips i
        WHERE i.ip=s.ip OR LOWER(i.mac) COLLATE latin1_general_ci=LOWER(s.mac) COLLATE latin1_general_ci
        ORDER BY IF(i.ip=s.ip, 0, 1), i.id DESC
        LIMIT 1
      ), 0) AS important
    FROM stats s
    WHERE s.id>:after_id
      AND s.ip IS NOT NULL
      AND s.ip<>''
      AND EXISTS (SELECT 1 FROM stats prev_exists WHERE prev_exists.ip=s.ip AND prev_exists.id<s.id)
    ORDER BY s.date_begin ASC, s.id ASC
  ");
  $stmt->execute(array('after_id' => $afterId));

  $changes = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['id'] = (int)$row['id'];
    $row['important'] = (int)$row['important'];
    $row['previous_status'] = ($row['previous_status'] ?? '') === '' ? null : $row['previous_status'];
    $changes[] = $row;
  }
  return $changes;
}

function discordNotificationMessages(array $changes): array {
  $header = 'FenPing status changes';
  $messages = array();
  $current = $header;

  foreach ($changes as $change) {
    $line = discordChangeLine($change);
    if (strlen($current) + strlen($line) + 1 > 1800) {
      $messages[] = $current;
      $current = $header;
    }
    $current .= PHP_EOL . $line;
  }

  if ($current !== $header)
    $messages[] = $current;

  return $messages;
}

function discordChangeLine(array $change): string {
  $name = trim((string)($change['name'] ?? ''));
  $ip = (string)($change['ip'] ?? '');
  $mac = strtolower((string)($change['mac'] ?? ''));
  $previous = (string)($change['previous_status'] ?? 'Unknown');
  $status = (string)($change['status'] ?? 'Unknown');
  $date = (string)($change['date_begin'] ?? '');
  $important = (int)($change['important'] ?? 0) === 1 ? ' important' : '';
  $label = $name !== '' ? "$name ($ip)" : $ip;
  $macPart = $mac !== '' ? " $mac" : '';

  return "- [$date]$important $label$macPart: $previous -> $status";
}

function discordPost(string $message): bool {
  $url = discordWebhookUrl();
  if ($url === '')
    return false;

  $payload = json_encode(array(
    'username' => 'FenPing',
    'content' => $message
  ));
  if ($payload === false)
    return false;

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
  curl_setopt($ch, CURLOPT_TIMEOUT, 8);
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  if ($error !== '' || $code < 200 || $code >= 300) {
    fwrite(STDERR, "Discord notification failed" . ($error !== '' ? ": $error" : ": HTTP $code") . PHP_EOL);
    return false;
  }

  return true;
}
