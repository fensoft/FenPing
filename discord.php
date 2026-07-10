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

  foreach (discordNotificationPayloads($changes) as $payload)
    discordPostPayload($payload);
}

function sendDiscordPortChangesForScan(int $scanId): void {
  if (!discordNotificationsEnabled())
    return;

  $changes = discordPortChangesForScan($scanId);
  foreach (discordPortNotificationPayloads($changes) as $payload)
    discordPostPayload($payload);
}

function sendDiscordRestartNotification(): bool {
  if (!discordNotificationsEnabled())
    return false;

  return discordPostPayload(discordRestartPayload());
}

function discordRestartPayload(): array {
  global $network, $myself;

  $host = gethostname();
  if ($host === false || $host === '')
    $host = 'fenping';

  $fields = array(
    discordEmbedField('Host', $host, true),
    discordEmbedField('Time', date('Y-m-d H:i:s T'), true)
  );

  if (($network ?? '') !== '')
    $fields[] = discordEmbedField('Network', $network . '.0/24', true);
  if (($myself ?? '') !== '')
    $fields[] = discordEmbedField('Address', $myself, true);

  return array(
    'username' => 'FenPing',
    'embeds' => array(array(
      'title' => 'FenPing restarted',
      'description' => 'The LAN appliance boot sequence completed.',
      'color' => 0x2563eb,
      'fields' => $fields,
      'timestamp' => date(DATE_ATOM)
    ))
  );
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

function discordNotificationPayloads(array $changes): array {
  $payloads = array();
  foreach (array_chunk($changes, 10) as $chunk) {
    $embeds = array();
    foreach ($chunk as $change)
      $embeds[] = discordChangeEmbed($change);

    $payloads[] = array(
      'username' => 'FenPing',
      'embeds' => $embeds
    );
  }
  return $payloads;
}

function discordPortChangesForScan(int $scanId): array {
  $stmt = db()->prepare("
    SELECT
      c.*,
      COALESCE(NULLIF((SELECT i.name FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1), ''), '') AS name,
      COALESCE(NULLIF((SELECT i.mac FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1), ''), '') AS mac
    FROM scan_port_changes c
    WHERE c.scan_id=:scan_id
    ORDER BY c.port ASC, c.protocol ASC
  ");
  $stmt->execute(array('scan_id' => $scanId));
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function discordPortNotificationPayloads(array $changes): array {
  $payloads = array();
  foreach (array_chunk($changes, 10) as $chunk) {
    $embeds = array();
    foreach ($chunk as $change)
      $embeds[] = discordPortChangeEmbed($change);
    $payloads[] = array('username' => 'FenPing', 'embeds' => $embeds);
  }
  return $payloads;
}

function discordPortChangeEmbed(array $change): array {
  $name = trim((string)($change['name'] ?? ''));
  $ip = (string)($change['ip'] ?? '');
  $protocol = strtolower((string)($change['protocol'] ?? ''));
  $port = (int)($change['port'] ?? 0);
  $type = (string)($change['change_type'] ?? 'changed');
  $label = $name !== '' ? $name : ($ip !== '' ? $ip : 'Unknown host');
  $action = array('appeared' => 'appeared', 'disappeared' => 'disappeared', 'changed' => 'changed version')[$type] ?? 'changed';
  $timestamp = strtotime((string)($change['created_at'] ?? ''));

  $embed = array(
    'title' => $label . ': ' . $port . '/' . $protocol . ' ' . $action,
    'description' => 'A network service changed.',
    'color' => discordPortChangeColor($type),
    'fields' => array(
      discordEmbedField('Host', $label, true),
      discordEmbedField('IP', $ip, true),
      discordEmbedField('Port', $port . '/' . $protocol, true),
      discordEmbedField('Previous', discordPortServiceLabel($change, 'previous'), false),
      discordEmbedField('Current', discordPortServiceLabel($change, 'current'), false),
      discordEmbedField('Scan', (string)($change['mode'] ?? '-'), true)
    )
  );
  if ($timestamp !== false)
    $embed['timestamp'] = date(DATE_ATOM, $timestamp);
  return $embed;
}

function discordPortServiceLabel(array $change, string $prefix): string {
  $service = trim((string)($change[$prefix . '_service'] ?? ''));
  $version = trim((string)($change[$prefix . '_version'] ?? ''));
  $value = trim($service . ' ' . $version);
  return $value === '' ? '-' : $value;
}

function discordPortChangeColor(string $type): int {
  if ($type === 'appeared')
    return 0x16a34a;
  if ($type === 'disappeared')
    return 0xdc2626;
  return 0xf59e0b;
}

function discordChangeEmbed(array $change): array {
  $name = trim((string)($change['name'] ?? ''));
  $ip = (string)($change['ip'] ?? '');
  $mac = strtolower((string)($change['mac'] ?? ''));
  $previous = (string)($change['previous_status'] ?? 'Unknown');
  $status = (string)($change['status'] ?? 'Unknown');
  $date = (string)($change['date_begin'] ?? '');
  $important = (int)($change['important'] ?? 0) === 1;
  $label = $name !== '' ? $name : ($ip !== '' ? $ip : 'Unknown host');
  $description = $important ? 'Important host changed state.' : 'Host changed state.';
  $timestamp = strtotime($date);

  $embed = array(
    'title' => $label . ' is now ' . ($status !== '' ? $status : 'Unknown'),
    'description' => $description,
    'color' => discordStatusColor($status),
    'fields' => array(
      discordEmbedField('Host', $label, true),
      discordEmbedField('IP', $ip, true),
      discordEmbedField('MAC', $mac, true),
      discordEmbedField('Previous', $previous, true),
      discordEmbedField('Current', $status, true),
      discordEmbedField('Important', $important ? 'Yes' : 'No', true),
      discordEmbedField('Time', $date, false)
    )
  );

  if ($timestamp !== false)
    $embed['timestamp'] = date(DATE_ATOM, $timestamp);

  return $embed;
}

function discordStatusColor(string $status): int {
  if ($status === 'Up')
    return 0x16a34a;
  if ($status === 'Down')
    return 0xdc2626;
  if ($status === 'arp')
    return 0x2563eb;
  if ($status === 'arp-down')
    return 0xf59e0b;
  return 0x64748b;
}

function discordEmbedField(string $name, string $value, bool $inline): array {
  $value = trim($value);
  if ($value === '')
    $value = '-';

  if (strlen($value) > 1000)
    $value = substr($value, 0, 997) . '...';

  return array(
    'name' => $name,
    'value' => $value,
    'inline' => $inline
  );
}

function discordPost(string $message): bool {
  return discordPostPayload(array(
    'username' => 'FenPing',
    'content' => $message
  ));
}

function discordPostPayload(array $payload): bool {
  $url = discordWebhookUrl();
  if ($url === '')
    return false;

  $json = json_encode($payload);
  if ($json === false)
    return false;

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
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
