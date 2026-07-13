<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;
trait TelegramChatDiscoveryBehavior
{
public function telegramBotConfigured(): bool {
  return $this->config->telegramBotToken !== '';
}
public function telegramBotFingerprint(): string {
  return $this->telegramBotConfigured()
    ? hash('sha256', $this->config->telegramBotToken)
    : '';
}
public function telegramSelectedChatId(): ?string {
  if (!$this->telegramBotConfigured())
    return null;
  $row = $this->db()->query("
    SELECT telegram_chat_id, telegram_bot_fingerprint
    FROM notification_delivery_settings
    WHERE id=1
  ")->fetch(PDO::FETCH_ASSOC);
  if ($row === false
      || !hash_equals($this->telegramBotFingerprint(), (string)($row['telegram_bot_fingerprint'] ?? ''))
      || $row['telegram_chat_id'] === null)
    return null;
  try {
    return $this->telegramNormalizeChatId($row['telegram_chat_id']);
  } catch (InvalidArgumentException) {
    return null;
  }
}
public function telegramNotificationsEnabled(): bool {
  return $this->telegramBotConfigured() && $this->telegramSelectedChatId() !== null;
}

public function telegramNormalizeChatId(mixed $value): string {
  if (is_int($value))
    $value = (string)$value;
  if (!is_string($value))
    throw new InvalidArgumentException('invalid Telegram chat ID');
  $value = trim($value);
  if (preg_match('/^-?[1-9][0-9]{0,15}$/', $value) !== 1)
    throw new InvalidArgumentException('invalid Telegram chat ID');
  return $value;
}

public function telegramEnsureBotState(): void {
  if (!$this->telegramBotConfigured())
    return;
  $fingerprint = $this->telegramBotFingerprint();
  $current = $this->db()->query("
    SELECT telegram_bot_fingerprint
    FROM notification_delivery_settings
    WHERE id=1
  ")->fetchColumn();
  if (is_string($current) && hash_equals($fingerprint, $current))
    return;
  $database = $this->db();
  try {
    $this->dbBeginImmediate($database);
    $current = $database->query("
      SELECT telegram_bot_fingerprint
      FROM notification_delivery_settings
      WHERE id=1
    ")->fetchColumn();
    if (!is_string($current) || !hash_equals($fingerprint, $current)) {
      $stmt = $database->prepare("
        INSERT INTO notification_delivery_settings (
          id, telegram_chat_id, telegram_bot_fingerprint
        ) VALUES (1, NULL, :fingerprint)
        ON CONFLICT(id) DO UPDATE SET
          telegram_chat_id=NULL,
          telegram_bot_fingerprint=excluded.telegram_bot_fingerprint
      ");
      $stmt->execute(array('fingerprint' => $fingerprint));
      $database->exec('DELETE FROM telegram_known_chats');
    }
    $this->dbCommit($database);
  } catch (Throwable $error) {
    $this->dbRollback($database);
    throw $error;
  }
}

public function telegramChatSelectionUpdate(mixed $chatId): ?string {
  $target = $chatId === null ? null : $this->telegramNormalizeChatId($chatId);
  if ($target !== null && !$this->telegramBotConfigured())
    throw new InvalidArgumentException('TELEGRAM_BOT_TOKEN is not configured');
  if ($this->telegramBotConfigured())
    $this->telegramEnsureBotState();

  if ($target !== null) {
    $exists = $this->db()->prepare('SELECT COUNT(*) FROM telegram_known_chats WHERE chat_id=:chat_id');
    $exists->execute(array('chat_id' => $target));
    if ((int)$exists->fetchColumn() !== 1)
      throw new InvalidArgumentException('Telegram chat must be discovered with getUpdates first');
  }

  $stmt = $this->db()->prepare("
    INSERT INTO notification_delivery_settings (
      id, telegram_chat_id, telegram_bot_fingerprint
    ) VALUES (1, :chat_id, :fingerprint)
    ON CONFLICT(id) DO UPDATE SET
      telegram_chat_id=excluded.telegram_chat_id,
      telegram_bot_fingerprint=excluded.telegram_bot_fingerprint
  ");
  $stmt->execute(array(
    'chat_id' => $target,
    'fingerprint' => $this->telegramBotConfigured() ? $this->telegramBotFingerprint() : null
  ));
  return $target;
}

public function telegramRefreshKnownChats(): array {
  if (!$this->telegramBotConfigured()) {
    return array(
      'configured' => false,
      'selected_chat_id' => null,
      'chats' => array()
    );
  }

  $this->telegramEnsureBotState();
  $offset = null;
  for ($page = 0; $page < 100; $page++) {
    $payload = array(
      'limit' => 100,
      'timeout' => 0,
      'allowed_updates' => array()
    );
    if ($offset !== null)
      $payload['offset'] = $offset;

    $updates = $this->telegramApiRequest('getUpdates', $payload);
    if (!array_is_list($updates) || count($updates) > 100)
      throw new RuntimeException('Telegram getUpdates returned an invalid result');

    $maxUpdateId = null;
    $contexts = array();
    foreach ($updates as $update) {
      if (!is_array($update))
        throw new RuntimeException('Telegram getUpdates returned an invalid update');
      $updateId = $this->telegramNormalizeUpdateId($update['update_id'] ?? null);
      $maxUpdateId = $maxUpdateId === null ? $updateId : max($maxUpdateId, $updateId);
      foreach ($this->telegramUpdateChatContexts($update) as $context)
        $contexts[] = array($context['chat'], $context['user'], $updateId);
    }
    $this->telegramStoreChatContexts($contexts);

    if (count($updates) < 100 || $maxUpdateId === null)
      break;
    $nextOffset = $maxUpdateId + 1;
    if ($offset !== null && $nextOffset <= $offset)
      throw new RuntimeException('Telegram getUpdates did not advance');
    $offset = $nextOffset;
  }
  if ($page === 100)
    throw new RuntimeException('Telegram has too many pending updates; refresh again');

  return $this->telegramKnownChatsResponse();
}

public function telegramKnownChatsResponse(): array {
  $selected = $this->telegramSelectedChatId();
  return array(
    'configured' => $this->telegramBotConfigured(),
    'selected_chat_id' => $selected,
    'chats' => $this->telegramKnownChats($selected)
  );
}

public function telegramKnownChats(?string $selected = null): array {
  $rows = $this->db()->query("
    SELECT
      chat_id, chat_type, chat_title, chat_username, chat_first_name, chat_last_name,
      user_id, user_is_bot, user_first_name, user_last_name, user_username,
      user_language_code, last_seen_at
    FROM telegram_known_chats
  ")->fetchAll(PDO::FETCH_ASSOC);

  $chats = array_map(function(array $row) use ($selected): array {
    $name = trim((string)($row['chat_title'] ?? ''));
    if ($name === '')
      $name = trim((string)($row['chat_first_name'] ?? '') . ' ' . (string)($row['chat_last_name'] ?? ''));
    if ($name === '' && (string)($row['chat_username'] ?? '') !== '')
      $name = '@' . $row['chat_username'];
    if ($name === '')
      $name = 'Chat ' . $row['chat_id'];

    $user = null;
    if ($row['user_id'] !== null) {
      $userName = trim((string)($row['user_first_name'] ?? '') . ' ' . (string)($row['user_last_name'] ?? ''));
      if ($userName === '' && (string)($row['user_username'] ?? '') !== '')
        $userName = '@' . $row['user_username'];
      if ($userName === '')
        $userName = 'User ' . $row['user_id'];
      $user = array(
        'id' => (string)$row['user_id'],
        'name' => $userName,
        'username' => $row['user_username'],
        'language_code' => $row['user_language_code'],
        'is_bot' => (int)($row['user_is_bot'] ?? 0) === 1
      );
    }

    return array(
      'id' => (string)$row['chat_id'],
      'type' => (string)$row['chat_type'],
      'name' => $name,
      'title' => $row['chat_title'],
      'username' => $row['chat_username'],
      'first_name' => $row['chat_first_name'],
      'last_name' => $row['chat_last_name'],
      'user' => $user,
      'last_seen_at' => (string)$row['last_seen_at'],
      'selected' => $selected !== null && hash_equals($selected, (string)$row['chat_id'])
    );
  }, $rows);

  usort($chats, static function(array $left, array $right): int {
    if ($left['selected'] !== $right['selected'])
      return $left['selected'] ? -1 : 1;
    $byName = strnatcasecmp((string)$left['name'], (string)$right['name']);
    return $byName !== 0 ? $byName : strcmp((string)$left['id'], (string)$right['id']);
  });
  return $chats;
}

public function telegramNormalizeUpdateId(mixed $value): int {
  if (is_int($value) && $value >= 0)
    return $value;
  if (is_string($value) && ctype_digit($value) && strlen($value) < 19)
    return (int)$value;
  throw new RuntimeException('Telegram getUpdates returned an invalid update ID');
}

public function telegramUpdateChatContexts(array $update): array {
  $contexts = array();
  foreach (array(
    'message', 'edited_message', 'channel_post', 'edited_channel_post',
    'business_message', 'edited_business_message'
  ) as $key) {
    $message = $update[$key] ?? null;
    if (is_array($message) && is_array($message['chat'] ?? null)) {
      $contexts[] = array(
        'chat' => $message['chat'],
        'user' => is_array($message['from'] ?? null) ? $message['from'] : null
      );
    }
  }
  foreach (array('my_chat_member', 'chat_member', 'chat_join_request') as $key) {
    $membership = $update[$key] ?? null;
    if (is_array($membership) && is_array($membership['chat'] ?? null)) {
      $contexts[] = array(
        'chat' => $membership['chat'],
        'user' => is_array($membership['from'] ?? null) ? $membership['from'] : null
      );
    }
  }
  $callback = $update['callback_query'] ?? null;
  $message = is_array($callback) ? ($callback['message'] ?? null) : null;
  if (is_array($message) && is_array($message['chat'] ?? null)) {
    $contexts[] = array(
      'chat' => $message['chat'],
      'user' => is_array($callback['from'] ?? null) ? $callback['from'] : null
    );
  }
  return $contexts;
}

public function telegramStoreChatContexts(array $contexts): void {
  if ($contexts === array())
    return;
  $database = $this->db();
  try {
    $this->dbBeginImmediate($database);
    foreach ($contexts as [$chat, $user, $updateId])
      $this->telegramKnownChatUpsert($chat, $user, $updateId);
    $this->dbCommit($database);
  } catch (Throwable $error) {
    $this->dbRollback($database);
    throw $error;
  }
}

public function telegramKnownChatUpsert(array $chat, ?array $user, int $updateId): void {
  $chatId = $this->telegramNormalizeChatId($chat['id'] ?? null);
  $type = (string)($chat['type'] ?? 'unknown');
  if (!in_array($type, array('private', 'group', 'supergroup', 'channel'), true))
    $type = 'unknown';

  $userId = null;
  if ($user !== null && array_key_exists('id', $user)) {
    try {
      $userId = $this->telegramNormalizeChatId($user['id']);
    } catch (InvalidArgumentException) {
      $user = null;
    }
  }

  $stmt = $this->db()->prepare("
    INSERT INTO telegram_known_chats (
      chat_id, chat_type, chat_title, chat_username, chat_first_name, chat_last_name,
      user_id, user_is_bot, user_first_name, user_last_name, user_username,
      user_language_code, last_update_id
    ) VALUES (
      :chat_id, :chat_type, :chat_title, :chat_username, :chat_first_name, :chat_last_name,
      :user_id, :user_is_bot, :user_first_name, :user_last_name, :user_username,
      :user_language_code, :last_update_id
    )
    ON CONFLICT(chat_id) DO UPDATE SET
      chat_type=excluded.chat_type,
      chat_title=COALESCE(excluded.chat_title, telegram_known_chats.chat_title),
      chat_username=COALESCE(excluded.chat_username, telegram_known_chats.chat_username),
      chat_first_name=COALESCE(excluded.chat_first_name, telegram_known_chats.chat_first_name),
      chat_last_name=COALESCE(excluded.chat_last_name, telegram_known_chats.chat_last_name),
      user_id=COALESCE(excluded.user_id, telegram_known_chats.user_id),
      user_is_bot=COALESCE(excluded.user_is_bot, telegram_known_chats.user_is_bot),
      user_first_name=COALESCE(excluded.user_first_name, telegram_known_chats.user_first_name),
      user_last_name=COALESCE(excluded.user_last_name, telegram_known_chats.user_last_name),
      user_username=COALESCE(excluded.user_username, telegram_known_chats.user_username),
      user_language_code=COALESCE(excluded.user_language_code, telegram_known_chats.user_language_code),
      last_update_id=MAX(telegram_known_chats.last_update_id, excluded.last_update_id),
      last_seen_at=CURRENT_TIMESTAMP
  ");
  $stmt->execute(array(
    'chat_id' => $chatId,
    'chat_type' => $type,
    'chat_title' => $this->telegramOptionalText($chat['title'] ?? null),
    'chat_username' => $this->telegramOptionalText($chat['username'] ?? null),
    'chat_first_name' => $this->telegramOptionalText($chat['first_name'] ?? null),
    'chat_last_name' => $this->telegramOptionalText($chat['last_name'] ?? null),
    'user_id' => $userId,
    'user_is_bot' => $user === null ? null : (($user['is_bot'] ?? false) ? 1 : 0),
    'user_first_name' => $this->telegramOptionalText($user['first_name'] ?? null),
    'user_last_name' => $this->telegramOptionalText($user['last_name'] ?? null),
    'user_username' => $this->telegramOptionalText($user['username'] ?? null),
    'user_language_code' => $this->telegramOptionalText($user['language_code'] ?? null),
    'last_update_id' => $updateId
  ));
}

public function telegramOptionalText(mixed $value): ?string {
  if (!is_scalar($value))
    return null;
  $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$value) ?? '');
  return $value === '' ? null : $this->notificationTextValue($value, 200);
}

public function telegramApiRequest(string $method, array $payload): mixed {
  if (!$this->telegramBotConfigured())
    throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured');
  if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $method) !== 1)
    throw new InvalidArgumentException('invalid Telegram Bot API method');

  try {
    $json = json_encode(
      $payload,
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $response = $this->fenpingHttpRequest(
      'https://api.telegram.org/bot' . $this->config->telegramBotToken . '/' . $method,
      array(
        'method' => 'POST',
        'headers' => array(
          'Content-Type' => 'application/json',
          'User-Agent' => 'FenPing Telegram notifier'
        ),
        'body' => $json,
        'timeout' => 8,
        'max_bytes' => 4 * 1024 * 1024
      )
    );
    $body = json_decode((string)$response['body'], true, flags: JSON_THROW_ON_ERROR);
  } catch (JsonException $error) {
    throw new RuntimeException('Telegram returned invalid JSON', 0, $error);
  }

  if ($response['status'] < 200 || $response['status'] >= 300
      || !is_array($body) || ($body['ok'] ?? false) !== true) {
    $message = 'Telegram ' . $method . ' failed (HTTP ' . $response['status'] . ')';
    $description = is_array($body ?? null) ? ($body['description'] ?? null) : null;
    if (is_string($description) && trim($description) !== '')
      $message .= ': ' . $this->notificationTextValue(
        preg_replace('/-?[0-9]{5,}/', '[redacted]', $description) ?? '', 240);
    throw new RuntimeException($message);
  }
  if (!array_key_exists('result', $body))
    throw new RuntimeException('Telegram ' . $method . ' returned an invalid result');
  return $body['result'];
}
}
