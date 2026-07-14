<?php

declare(strict_types=1);

namespace FenPing\Status;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use FenPing\Config\AppConfig;
use FenPing\Http\HttpClient;

final readonly class TelegramApiClient
{
    public function __construct(private AppConfig $config, private HttpClient $http)
    {
    }

public function telegramApiRequest(string $method, array $payload): mixed {
  if ($this->config->telegramBotToken === '')
    throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured');
  if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $method) !== 1)
    throw new InvalidArgumentException('invalid Telegram Bot API method');

  try {
    $json = json_encode(
      $payload,
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $response = $this->http->request(
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
    $body = json_decode((string)$response->body, true, flags: JSON_THROW_ON_ERROR);
  } catch (JsonException $error) {
    throw new RuntimeException('Telegram returned invalid JSON', 0, $error);
  }

  if ($response->status < 200 || $response->status >= 300
      || !is_array($body) || ($body['ok'] ?? false) !== true) {
    $message = 'Telegram ' . $method . ' failed (HTTP ' . $response->status . ')';
    $description = is_array($body ?? null) ? ($body['description'] ?? null) : null;
    if (is_string($description) && trim($description) !== '')
      $message .= ': ' . $this->textValue(
        preg_replace('/-?[0-9]{5,}/', '[redacted]', $description) ?? '', 240);
    throw new RuntimeException($message);
  }
  if (!array_key_exists('result', $body))
    throw new RuntimeException('Telegram ' . $method . ' returned an invalid result');
  return $body['result'];
}

private function textValue(string $value, int $maxCharacters = 400): string {
  $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
  if ($value === '') return '-';
  if (strlen($value) <= $maxCharacters) return $value;
  if (preg_match('/^.{0,' . max(1, $maxCharacters - 1) . '}/us', $value, $matches)) return rtrim($matches[0]) . '…';
  return '-';
}
}
