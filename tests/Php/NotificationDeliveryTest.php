<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;
use FenPing\Application;
use FenPing\Config\AppConfig;
use FenPing\Http\HttpTransport;
use FenPing\Realtime\NullLiveUpdatePublisher;
use InvalidArgumentException;

final class NotificationDeliveryTest extends IntegrationTestCase
{
    private array $temporaryDatabases = [];

    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->app()->auth()->isAuthenticated()) {
            $this->app()->auth()->logout();
        }
        foreach ($this->temporaryDatabases as $path) {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
        $this->temporaryDatabases = [];
    }

    public function testDefaultsPersistenceBackupAndOlderBackupCompatibility(): void
    {
        $backend = $this->app();
        self::assertSame($backend->notificationRules()->notificationDefaultRules(), $backend->notificationRules()->notificationRules());

        $rules = [
            'restart' => false,
            'host_status' => ['normal' => false, 'important' => true],
            'service_changes' => ['normal' => true, 'important' => false],
            'ip_conflicts' => false,
        ];
        self::assertSame($rules, $backend->notificationRules()->notificationRulesUpdate($rules));
        self::assertSame($rules, $backend->notificationRules()->notificationRules());
        $database = $this->app()->database()->connection();
        $database->exec("
            UPDATE notification_delivery_settings
            SET telegram_chat_id='123456789', telegram_bot_fingerprint='not-a-secret'
            WHERE id=1
        ");
        $database->exec("
            INSERT INTO telegram_known_chats (
              chat_id, chat_type, chat_first_name, user_id, user_first_name, last_update_id
            ) VALUES ('123456789', 'private', 'Alice', '123456789', 'Alice', 1)
        ");

        $path = tempnam(sys_get_temp_dir(), 'fenping-notification-rules-');
        self::assertIsString($path);
        try {
            $backend->backupArchives()->backupWriteDatabaseJson($path);
            $document = $backend->backupTools()->backupReadJson($path, 'db.json');
            self::assertCount(1, $document['tables']['notification_delivery_settings']['rows']);
            self::assertNotContains(
                'telegram_chat_id',
                $document['tables']['notification_delivery_settings']['columns'],
            );
            self::assertNotContains(
                'telegram_bot_fingerprint',
                $document['tables']['notification_delivery_settings']['columns'],
            );
            self::assertArrayNotHasKey('telegram_known_chats', $document['tables']);

            $this->app()->database()->connection()->exec('DELETE FROM notification_delivery_settings');
            $this->restoreDocument($document);
            self::assertSame($rules, $backend->notificationRules()->notificationRules());
            self::assertNull($backend->telegramChats()->telegramSelectedChatId());
            self::assertSame(
                0,
                (int) $database->query('SELECT COUNT(*) FROM telegram_known_chats')->fetchColumn(),
            );

            $document['tables']['notification_delivery_settings']['rows'] = [];
            $this->restoreDocument($document);
            self::assertSame($backend->notificationRules()->notificationDefaultRules(), $backend->notificationRules()->notificationRules());
            self::assertSame(
                1,
                (int) $database->query(
                    'SELECT COUNT(*) FROM notification_delivery_settings WHERE id=1',
                )->fetchColumn(),
            );

            unset($document['tables']['notification_delivery_settings']);
            $this->restoreDocument($document);
            self::assertSame($backend->notificationRules()->notificationDefaultRules(), $backend->notificationRules()->notificationRules());
            self::assertSame(
                1,
                (int) $this->app()->database()->connection()
                    ->query('SELECT COUNT(*) FROM notification_delivery_settings WHERE id=1')
                    ->fetchColumn(),
            );
        } finally {
            @unlink($path);
        }
    }

    public function testManagedImportanceAndSharedFiltersClassifyEveryEventOnce(): void
    {
        $database = $this->app()->database()->connection();
        $importantId = $this->app()->hosts()->create('192.0.2.10', '02:00:00:00:00:10');
        $database->exec("UPDATE ips SET important=1 WHERE id=$importantId");

        $this->app()->pingRepository()->save([
            ['ip' => '192.0.2.10', 'mac' => '02:00:00:00:00:10', 'status' => 'Up'],
            ['ip' => '192.0.2.20', 'mac' => '02:00:00:00:00:20', 'status' => 'Up'],
        ]);
        $afterId = $this->app()->discord()->statsMaxId();
        $this->app()->pingRepository()->save([
            ['ip' => '192.0.2.10', 'mac' => '02:00:00:00:00:10', 'status' => 'Down'],
            ['ip' => '192.0.2.20', 'mac' => '02:00:00:00:00:20', 'status' => 'Down'],
        ]);

        $classified = array_column(
            $this->app()->discord()->discordStatusChangesSince($afterId),
            'important',
            'ip',
        );
        self::assertSame(1, $classified['192.0.2.10']);
        self::assertSame(0, $classified['192.0.2.20']);

        $database->exec("
            INSERT INTO scan_port_changes (
              scan_id, ip, mode, change_type, protocol, port, current_service
            ) VALUES
              (91, '192.0.2.10', 'standard', 'appeared', 'tcp', 443, 'https'),
              (92, '192.0.2.20', 'standard', 'appeared', 'tcp', 80, 'http')
        ");
        self::assertSame(1, $this->app()->discord()->discordPortChangesForScan(91)[0]['important']);
        self::assertSame(0, $this->app()->discord()->discordPortChangesForScan(92)[0]['important']);

        $this->app()->notificationRules()->notificationRulesUpdate([
            'restart' => true,
            'host_status' => ['normal' => false, 'important' => true],
            'service_changes' => ['normal' => true, 'important' => false],
            'ip_conflicts' => true,
        ]);
        $events = [
            ['id' => 'normal', 'important' => 0],
            ['id' => 'important', 'important' => 1],
            ['id' => 'unmanaged'],
        ];
        self::assertSame(
            ['important'],
            array_column($this->app()->notificationRules()->filterStatusChanges($events), 'id'),
        );
        self::assertSame(
            ['normal', 'unmanaged'],
            array_column($this->app()->notificationRules()->filterServiceChanges($events), 'id'),
        );
    }

    public function testDiscordUsesExplicitAllowedMentionsForEveryNotification(): void
    {
        $transport = new FakeNotificationHttpTransport();
        $application = $this->configuredApplication(
            $transport,
            mention: '123456789012345678',
            telegram: false,
        );
        $application->discord()->sendDiscordStatusChanges([
            $this->statusChange('normal', 0),
            $this->statusChange('important', 1),
        ]);

        self::assertCount(2, $transport->requests);
        $normal = json_decode($transport->requests[0]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
        $important = json_decode($transport->requests[1]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('<@123456789012345678>', $normal['content']);
        self::assertSame(['users' => ['123456789012345678']], $normal['allowed_mentions']);
        self::assertSame('<@123456789012345678>', $important['content']);
        self::assertSame(['users' => ['123456789012345678']], $important['allowed_mentions']);

        $servicePayloads = $application->discord()->discordPortNotificationPayloads([
            ['change_type' => 'appeared', 'protocol' => 'tcp', 'port' => 80, 'important' => 0],
            ['change_type' => 'appeared', 'protocol' => 'tcp', 'port' => 443, 'important' => 1],
        ]);
        self::assertCount(2, $servicePayloads);
        foreach ($servicePayloads as $servicePayload) {
            self::assertSame('<@123456789012345678>', $servicePayload['content']);
            self::assertSame(
                ['users' => ['123456789012345678']],
                $servicePayload['allowed_mentions'],
            );
        }

        $restart = $application->discord()->discordRestartPayload();
        self::assertSame('<@123456789012345678>', $restart['content']);
        self::assertSame(['users' => ['123456789012345678']], $restart['allowed_mentions']);
        $conflict = $application->discord()->discordIpConflictPayloads([[]])[0];
        self::assertSame('<@123456789012345678>', $conflict['content']);
        self::assertSame(['users' => ['123456789012345678']], $conflict['allowed_mentions']);

        self::assertTrue($application->discord()->discordPost('Plain notification'));
        $plain = json_decode(
            $transport->requests[array_key_last($transport->requests)]['options']['body'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame("<@123456789012345678>\nPlain notification", $plain['content']);
        self::assertSame(['users' => ['123456789012345678']], $plain['allowed_mentions']);

        $everyone = $this->configuredApplication(
            new FakeNotificationHttpTransport(),
            mention: '@everyone',
            telegram: false,
        );
        $payload = $everyone->discord()->discordNotificationPayloads([$this->statusChange('important', 1)])[0];
        self::assertSame('@everyone', $payload['content']);
        self::assertSame(['parse' => ['everyone']], $payload['allowed_mentions']);
        $restart = $everyone->discord()->discordRestartPayload();
        self::assertSame('@everyone', $restart['content']);
        self::assertSame(['parse' => ['everyone']], $restart['allowed_mentions']);
        $conflict = $everyone->discord()->discordIpConflictPayloads([[]])[0];
        self::assertSame('@everyone', $conflict['content']);
        self::assertSame(['parse' => ['everyone']], $conflict['allowed_mentions']);

        $disabledPayload = $this->app()->discord()->discordNotificationPayloads([
            $this->statusChange('normal', 0),
        ])[0];
        self::assertArrayNotHasKey('content', $disabledPayload);
        self::assertSame(['parse' => []], $disabledPayload['allowed_mentions']);
    }

    public function testTelegramSharesRulesSplitsTextAndDoesNotBlockOnDiscordFailure(): void
    {
        $transport = new FakeNotificationHttpTransport();
        $transport->enqueue(['status' => 503, 'headers' => [], 'body' => 'unavailable']);
        $transport->enqueue(['status' => 200, 'headers' => [], 'body' => '{"ok":true,"result":{}}']);
        $application = $this->configuredApplication($transport, mention: '@everyone');

        $results = $application->notifications()->sendRestartNotification();
        self::assertSame(['discord' => false, 'telegram' => true], $results);
        self::assertCount(2, $transport->requests);
        self::assertStringContainsString('discord.test', $transport->requests[0]['url']);
        self::assertStringContainsString('/sendMessage', $transport->requests[1]['url']);

        $statuses = $application->operations()->statuses();
        self::assertSame('failure', $statuses['notification_delivery']['state']);
        self::assertSame('success', $statuses['telegram_notification_delivery']['state']);
        self::assertSame('HTTP 503', $statuses['notification_delivery']['last_error']);
        $transport->enqueue(['status' => 204, 'headers' => [], 'body' => '']);
        $transport->enqueue(['status' => 502, 'headers' => [], 'body' => '{"ok":false}']);
        $inverse = $application->notifications()->sendRestartNotification();
        self::assertSame(['discord' => true, 'telegram' => false], $inverse);
        $statuses = $application->operations()->statuses();
        self::assertSame('success', $statuses['notification_delivery']['state']);
        self::assertSame('failure', $statuses['telegram_notification_delivery']['state']);
        self::assertSame(
            'Telegram sendMessage failed (HTTP 502)',
            $statuses['telegram_notification_delivery']['last_error'],
        );

        $transport->enqueue(['status' => 200, 'headers' => [], 'body' => '{"ok":false}']);
        self::assertFalse($application->telegram()->telegramPostText('API response validation'));
        $statuses = $application->operations()->statuses();
        self::assertSame(
            'Telegram sendMessage failed (HTTP 200)',
            $statuses['telegram_notification_delivery']['last_error'],
        );


        $application->notificationRules()->notificationRulesUpdate([
            'restart' => true,
            'host_status' => ['normal' => false, 'important' => true],
            'service_changes' => ['normal' => true, 'important' => true],
            'ip_conflicts' => true,
        ]);
        $application->telegram()->sendTelegramStatusChanges([
            $this->statusChange('normal', 0),
            $this->statusChange('important', 1),
        ]);
        $request = json_decode(
            $transport->requests[array_key_last($transport->requests)]['options']['body'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('-1001234567890', $request['chat_id']);
        self::assertStringContainsString('important', $request['text']);
        self::assertStringNotContainsString('normal', $request['text']);

        $messages = $application->telegram()->telegramBatchMessages(
            'FenPing test',
            array_fill(0, 30, str_repeat('é', 300)),
        );
        self::assertGreaterThan(1, count($messages));
        foreach ($messages as $message) {
            self::assertLessThan(4096, strlen($message));
        }

        $application->notificationRules()->notificationRulesUpdate([
            'restart' => true,
            'host_status' => ['normal' => false, 'important' => false],
            'service_changes' => ['normal' => true, 'important' => true],
            'ip_conflicts' => true,
        ]);
        $requestCount = count($transport->requests);
        $application->discord()->sendDiscordStatusChanges([$this->statusChange('important', 1)]);
        $application->telegram()->sendTelegramStatusChanges([$this->statusChange('important', 1)]);
        self::assertCount($requestCount, $transport->requests);

        $application->notificationRules()->notificationRulesUpdate([
            'restart' => true,
            'host_status' => ['normal' => true, 'important' => true],
            'service_changes' => ['normal' => true, 'important' => true],
            'ip_conflicts' => true,
        ]);
        self::assertCount($requestCount, $transport->requests, 're-enabling rules must not replay disabled events');
    }

    public function testNotificationApiIsStrictAuthenticatedAndRedactsEnvironmentValues(): void
    {
        $get = $this->app()->api()->handle($this->request('GET', '/api/notify'));
        self::assertSame(200, $get->status);
        $getBody = json_decode($get->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->app()->notificationRules()->notificationDefaultRules(), $getBody['delivery']['rules']);
        self::assertFalse($getBody['delivery']['discord']['configured']);
        self::assertFalse($getBody['delivery']['telegram']['configured']);
        self::assertFalse($getBody['delivery']['telegram']['chat_selected']);

        $validRules = [
            'restart' => false,
            'host_status' => ['normal' => true, 'important' => false],
            'service_changes' => ['normal' => false, 'important' => true],
            'ip_conflicts' => true,
        ];
        $guest = $this->app()->api()->handle(
            $this->request('PUT', '/api/notify/delivery', ['rules' => $validRules]),
        );
        self::assertSame(403, $guest->status);

        self::assertTrue($this->app()->auth()->login(''));
        $saved = $this->app()->api()->handle(
            $this->request('PUT', '/api/notify/delivery', ['rules' => $validRules]),
        );
        self::assertSame(200, $saved->status);
        self::assertSame(
            $validRules,
            json_decode($saved->body, true, flags: JSON_THROW_ON_ERROR)['rules'],
        );

        $invalidBodies = [
            [],
            ['rules' => $validRules, 'unknown' => true],
            ['rules' => ['restart' => false]],
            ['rules' => $validRules + ['unknown' => true]],
            ['rules' => array_replace($validRules, ['restart' => 1])],
            ['rules' => array_replace($validRules, ['host_status' => ['normal' => true]])],
        ];
        foreach ($invalidBodies as $body) {
            $response = $this->app()->api()->handle(
                $this->request('PUT', '/api/notify/delivery', $body),
            );
            self::assertSame(400, $response->status, $response->body);
        }

        $configured = $this->configuredApplication(
            new FakeNotificationHttpTransport(),
            mention: '123456789012345678',
        );
        $response = $configured->api()->handle($this->request('GET', '/api/notify'));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($body['delivery']['discord']['configured']);
        self::assertSame('user', $body['delivery']['discord']['mention_target']);
        self::assertTrue($body['delivery']['telegram']['configured']);
        self::assertTrue($body['delivery']['telegram']['chat_selected']);
        self::assertStringNotContainsString('secret-webhook', $response->body);
        self::assertStringNotContainsString('123456:ABC_secret_token', $response->body);
        self::assertStringNotContainsString('-1001234567890', $response->body);
        self::assertStringNotContainsString('123456789012345678', $response->body);
    }

    public function testTelegramChatsAreDiscoveredWithUserInfoSelectedAndUsed(): void
    {
        $transport = new FakeNotificationHttpTransport();
        $application = $this->configuredApplication(
            $transport,
            discord: false,
            telegram: true,
            selectTelegram: false,
        );

        $guest = $application->api()->handle(
            $this->request('GET', '/api/notify/telegram/chats'),
        );
        self::assertSame(403, $guest->status);
        self::assertTrue($application->auth()->login(''));

        $transport->enqueue([
            'status' => 200,
            'headers' => [],
            'body' => json_encode([
                'ok' => true,
                'result' => [
                    [
                        'update_id' => 101,
                        'message' => [
                            'chat' => [
                                'id' => 123456789,
                                'type' => 'private',
                                'username' => 'alice',
                                'first_name' => 'Alice',
                                'last_name' => 'Example',
                            ],
                            'from' => [
                                'id' => 123456789,
                                'is_bot' => false,
                                'first_name' => 'Alice',
                                'last_name' => 'Example',
                                'username' => 'alice',
                                'language_code' => 'en',
                            ],
                        ],
                    ],
                    [
                        'update_id' => 102,
                        'message' => [
                            'chat' => [
                                'id' => -1009876543210,
                                'type' => 'supergroup',
                                'title' => 'Network team',
                                'username' => 'network_team',
                            ],
                            'from' => [
                                'id' => 987654321,
                                'is_bot' => false,
                                'first_name' => 'Bob',
                                'username' => 'bob',
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $discovered = $application->api()->handle(
            $this->request('GET', '/api/notify/telegram/chats'),
        );
        self::assertSame(200, $discovered->status);
        $body = json_decode($discovered->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($body['configured']);
        self::assertNull($body['selected_chat_id']);
        self::assertCount(2, $body['chats']);
        $private = array_values(array_filter(
            $body['chats'],
            static fn(array $chat): bool => $chat['id'] === '123456789',
        ))[0];
        self::assertSame('Alice Example', $private['name']);
        self::assertSame('private', $private['type']);
        self::assertSame('alice', $private['username']);
        self::assertSame('123456789', $private['user']['id']);
        self::assertSame('Alice Example', $private['user']['name']);
        self::assertSame('en', $private['user']['language_code']);
        self::assertStringNotContainsString('123456:ABC_secret_token', $discovered->body);

        $saved = $application->api()->handle($this->request(
            'PUT',
            '/api/notify/delivery',
            [
                'rules' => $application->notificationRules()->notificationDefaultRules(),
                'telegram_chat_id' => '123456789',
            ],
        ));
        self::assertSame(200, $saved->status);
        self::assertTrue(
            json_decode($saved->body, true, flags: JSON_THROW_ON_ERROR)['telegram']['chat_selected'],
        );
        self::assertSame('123456789', $application->telegramChats()->telegramSelectedChatId());
        self::assertTrue($application->telegramChats()->telegramNotificationsEnabled());

        self::assertTrue($application->telegram()->telegramPostText('Selected chat test'));
        $sent = json_decode(
            $transport->requests[array_key_last($transport->requests)]['options']['body'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('123456789', $sent['chat_id']);

        $transport->enqueue([
            'status' => 200,
            'headers' => [],
            'body' => '{"ok":true,"result":[]}',
        ]);
        $refreshed = $application->api()->handle(
            $this->request('GET', '/api/notify/telegram/chats'),
        );
        $refreshedBody = json_decode($refreshed->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('123456789', $refreshedBody['selected_chat_id']);
        self::assertCount(2, $refreshedBody['chats'], 'known chats must survive an empty refresh');

        $invalid = $application->api()->handle($this->request(
            'PUT',
            '/api/notify/delivery',
            [
                'rules' => $application->notificationRules()->notificationDefaultRules(),
                'telegram_chat_id' => '999999999',
            ],
        ));
        self::assertSame(400, $invalid->status);
        $invalidSyntax = $application->api()->handle($this->request(
            'PUT',
            '/api/notify/delivery',
            [
                'rules' => $application->notificationRules()->notificationDefaultRules(),
                'telegram_chat_id' => 'not-a-chat',
            ],
        ));
        self::assertSame(400, $invalidSyntax->status);

        $cleared = $application->api()->handle($this->request(
            'PUT',
            '/api/notify/delivery',
            [
                'rules' => $application->notificationRules()->notificationDefaultRules(),
                'telegram_chat_id' => null,
            ],
        ));
        self::assertSame(200, $cleared->status);
        self::assertFalse(
            json_decode($cleared->body, true, flags: JSON_THROW_ON_ERROR)['telegram']['chat_selected'],
        );
        self::assertFalse($application->telegramChats()->telegramNotificationsEnabled());
    }

    public function testTelegramBotTokenChangeInvalidatesKnownChatsAndSelection(): void
    {
        $application = $this->configuredApplication(new FakeNotificationHttpTransport());
        self::assertTrue($application->telegramChats()->telegramNotificationsEnabled());

        $rotated = Application::forConfig(
            $this->copyConfig(
                databasePath: $application->config()->databasePath,
                telegramBotToken: '654321:different_secret_token',
            ),
            new NullLiveUpdatePublisher(),
            new FakeNotificationHttpTransport(),
        );
        $rotated->database()->initialize();
        self::assertNull($rotated->telegramChats()->telegramSelectedChatId());
        self::assertFalse($rotated->telegramChats()->telegramNotificationsEnabled());

        $rotated->telegramChats()->telegramEnsureBotState();
        self::assertSame(
            0,
            (int) $rotated->database()->connection()
                ->query('SELECT COUNT(*) FROM telegram_known_chats')
                ->fetchColumn(),
        );
    }

    public function testRestartCliSupportsBothProvidersAndLegacyDiscordCommand(): void
    {
        $transport = new FakeNotificationHttpTransport();
        $application = $this->configuredApplication($transport, telegram: true);

        ob_start();
        try {
            self::assertSame(0, $application->cli()->run(['cli.php', 'notify-restart']));
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertStringContainsString('discord restart notification sent', $output);
        self::assertStringContainsString('telegram restart notification sent', $output);

        ob_start();
        try {
            self::assertSame(0, $application->cli()->run(['cli.php', 'discord-restart']));
            $legacyOutput = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertStringContainsString('discord restart notification sent', $legacyOutput);
    }

    public function testDiscordMentionEnvironmentNormalization(): void
    {
        $previous = getenv('DISCORD_MENTION');
        try {
            foreach ([
                '' => '',
                '@everyone' => '@everyone',
                '123456789' => '123456789',
                '@123456789' => '123456789',
                '<@123456789>' => '123456789',
            ] as $input => $expected) {
                putenv('DISCORD_MENTION=' . $input);
                self::assertSame(
                    $expected,
                    AppConfig::fromEnvironment($this->app()->config()->projectDir)->discordMention,
                );
            }
        } finally {
            $this->restoreEnvironment('DISCORD_MENTION', $previous);
        }
    }

    public function testInvalidDiscordMentionIsRejectedAtStartup(): void
    {
        $previous = getenv('DISCORD_MENTION');
        try {
            putenv('DISCORD_MENTION=@not-a-user');
            $this->expectException(InvalidArgumentException::class);
            AppConfig::fromEnvironment($this->app()->config()->projectDir);
        } finally {
            $this->restoreEnvironment('DISCORD_MENTION', $previous);
        }
    }

    public function testTelegramTokenIsTheOnlyEnvironmentConfiguration(): void
    {
        $application = Application::forConfig(
            $this->copyConfig(telegramBotToken: '123456:token'),
            new NullLiveUpdatePublisher(),
            new FakeNotificationHttpTransport(),
        );
        $application->database()->initialize();
        self::assertTrue($application->telegramChats()->telegramBotConfigured());
        self::assertFalse($application->telegramChats()->telegramNotificationsEnabled());
        self::assertNull($application->telegramChats()->telegramSelectedChatId());
    }

    private function restoreDocument(array $document): void
    {
        ob_start();
        try {
            $this->app()->backupDocuments()->backupRestoreDatabase($document);
        } finally {
            ob_end_clean();
        }
    }

    private function statusChange(string $name, int $important): array
    {
        return [
            'name' => $name,
            'ip' => $important === 1 ? '192.0.2.10' : '192.0.2.20',
            'mac' => $important === 1 ? '02:00:00:00:00:10' : '02:00:00:00:00:20',
            'previous_status' => 'Up',
            'status' => 'Down',
            'date_begin' => '2026-07-13 12:00:00',
            'important' => $important,
        ];
    }

    private function request(string $method, string $uri, ?array $body = null): Request
    {
        return new Request(
            $method,
            $uri,
            [],
            [],
            [],
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            [],
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function configuredApplication(
        FakeNotificationHttpTransport $transport,
        string $mention = '',
        bool $discord = true,
        bool $telegram = true,
        bool $selectTelegram = true,
    ): Application {
        $databasePath = tempnam(sys_get_temp_dir(), 'fenping-notification-provider-');
        self::assertIsString($databasePath);
        @unlink($databasePath);
        $this->temporaryDatabases[] = $databasePath;

        $application = Application::forConfig(
            $this->copyConfig(
                databasePath: $databasePath,
                discordWebhookUrl: $discord ? 'https://discord.test/webhook/secret-webhook' : '',
                discordMention: $mention,
                telegramBotToken: $telegram ? '123456:ABC_secret_token' : '',
            ),
            new NullLiveUpdatePublisher(),
            $transport,
        );
        $application->database()->initialize();
        if ($telegram && $selectTelegram) {
            $application->telegramChats()->telegramEnsureBotState();
            $application->telegramChats()->telegramKnownChatUpsert(
                ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'FenPing tests'],
                ['id' => 123456789, 'is_bot' => false, 'first_name' => 'Test'],
                1,
            );
            $application->telegramChats()->telegramChatSelectionUpdate('-1001234567890');
        }
        return $application;
    }

    private function copyConfig(
        ?string $databasePath = null,
        string $discordWebhookUrl = '',
        string $discordMention = '',
        string $telegramBotToken = '',
    ): AppConfig {
        $base = $this->app()->config();
        return new AppConfig(
            projectDir: $base->projectDir,
            databasePath: $databasePath ?? $base->databasePath,
            dhcpNetwork: $base->dhcpNetwork,
            extraNetworks: $base->extraNetworks,
            interface: $base->interface,
            applianceIp: $base->applianceIp,
            dhcpDynamicBegin: $base->dhcpDynamicBegin,
            dhcpDynamicEnd: $base->dhcpDynamicEnd,
            password: $base->password,
            secret: $base->secret,
            discordWebhookUrl: $discordWebhookUrl,
            dataDir: $base->dataDir,
            discordMention: $discordMention,
            telegramBotToken: $telegramBotToken,
            inventoryDownRetentionDays: $base->inventoryDownRetentionDays,
            dhcpDefaultRouter: $base->dhcpDefaultRouter,
            healthFailureWindowHours: $base->healthFailureWindowHours,
            healthPingMaxAgeMinutes: $base->healthPingMaxAgeMinutes,
            healthDiscoveryMaxAgeMinutes: $base->healthDiscoveryMaxAgeMinutes,
            healthLeaseImportMaxAgeMinutes: $base->healthLeaseImportMaxAgeMinutes,
            healthOuiMaxAgeDays: $base->healthOuiMaxAgeDays,
            healthBackupMaxAgeDays: $base->healthBackupMaxAgeDays,
            healthScanQueueMaxAgeMinutes: $base->healthScanQueueMaxAgeMinutes,
            healthDiskWarningPercent: $base->healthDiskWarningPercent,
            healthDiskCriticalPercent: $base->healthDiskCriticalPercent,
            healthDhcpWarningPercent: $base->healthDhcpWarningPercent,
            healthDhcpCriticalPercent: $base->healthDhcpCriticalPercent,
            dockerNetworkNames: $base->dockerNetworkNames,
        );
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}

final class FakeNotificationHttpTransport implements HttpTransport
{
    public array $requests = [];
    private array $responses = [];

    public function enqueue(array $response): void
    {
        $this->responses[] = $response;
    }

    public function request(string $url, array $options = []): array
    {
        $this->requests[] = ['url' => $url, 'options' => $options];
        if ($this->responses !== []) {
            return array_shift($this->responses);
        }
        if (str_contains($url, 'api.telegram.org')) {
            return ['status' => 200, 'headers' => [], 'body' => '{"ok":true,"result":{}}'];
        }
        return ['status' => 204, 'headers' => [], 'body' => ''];
    }
}
