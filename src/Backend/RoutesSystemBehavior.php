<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use FenPing\Network\NetworkPolicyException;
use FenPing\Realtime\LiveUpdateScope;

trait RoutesSystemBehavior
{
public function systemApiRoutes(): array {
  return array(
    $this->apiRoute('GET', '/health', 'handleHealth'),
    $this->apiRoute('GET', '/inventory', 'handleInventory'),
    $this->apiRoute('GET', '/notify', 'handleNotify'),
    $this->apiRoute('GET', '/notify/telegram/chats', 'handleTelegramChats', 'session'),
    $this->apiRoute(
      'PUT',
      '/notify/delivery',
      'handleNotificationDeliveryUpdate',
      'session',
      array('live' => array(LiveUpdateScope::All))
    ),
    $this->apiRoute('POST', '/ping/refresh', 'handlePingRefresh', 'session')
  );
}

public function handleHealth(array $params): array {
  return $this->getHealth();
}

public function handleInventory(array $params): array {
  $requestedNetwork = $_GET['network'] ?? null;
  if ($requestedNetwork !== null && !is_scalar($requestedNetwork))
    $this->jsonError(400, 'invalid network');
  try {
    $selected = $this->networks->forCidr($requestedNetwork === null ? null : (string)$requestedNetwork);
  } catch (NetworkPolicyException $error) {
    $this->jsonError($error->httpStatus, $error->getMessage());
  }
  return array(
    'network' => $selected->prefix(),
    'selected_network' => $selected->cidr,
    'dhcp_network' => $this->config->dhcpNetwork->cidr,
    'networks' => $this->networks->descriptors(),
    'hosts' => $this->getInventory($selected->cidr)
  );
}

public function handleNotify(array $params): array {
  return $this->get_notify();
}

public function handleTelegramChats(array $params): array {
  try {
    return $this->telegramRefreshKnownChats();
  } catch (RuntimeException $error) {
    $this->jsonError(502, $error->getMessage());
  }
}

public function handleNotificationDeliveryUpdate(array $params): array {
  $body = $this->requestBody();
  try {
    $updateTelegramChat = array_key_exists('telegram_chat_id', $body);
    $expected = $updateTelegramChat
      ? array('rules', 'telegram_chat_id')
      : array('rules');
    $this->notificationRequireExactKeys($body, $expected);
    if (!is_array($body['rules']))
      throw new InvalidArgumentException('invalid notification rules');
    $rules = $this->notificationValidateRules($body['rules']);
    if ($updateTelegramChat)
      $this->telegramChatSelectionUpdate($body['telegram_chat_id']);
    $this->notificationRulesUpdate($rules);
  } catch (InvalidArgumentException $error) {
    $this->jsonError(400, $error->getMessage());
  }
  return $this->notificationDelivery();
}

public function handlePingRefresh(array $params): array {
  $body = $this->requestBody();
  $requestedNetwork = $body['network'] ?? null;
  if ($requestedNetwork !== null && !is_scalar($requestedNetwork))
    $this->jsonError(400, 'invalid network');
  try {
    $selected = $this->networks->forCidr($requestedNetwork === null ? null : (string)$requestedNetwork);
  } catch (NetworkPolicyException $error) {
    $this->jsonError($error->httpStatus, $error->getMessage());
  }
  $previousScanNetwork = getenv('SCAN_NETWORK');
  putenv('SCAN_NETWORK=' . $selected->cidr);
  try {
    $command = '/usr/bin/doas /usr/bin/php ' . escapeshellarg($this->config->projectDir . '/cli.php') . ' ping';
    $output = array();
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
  } finally {
    $previousScanNetwork === false ? putenv('SCAN_NETWORK') : putenv('SCAN_NETWORK=' . $previousScanNetwork);
  }

  if ($code !== 0)
    $this->jsonError(409, trim(implode("\n", $output)) ?: 'scan already running');

  return array('status' => 'complete', 'network' => $selected->cidr);
}
}
