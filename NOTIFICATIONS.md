# Notification provider setup

FenPing can send the same notification events to Discord and Telegram. Each provider is optional and fails independently: a failure at one provider does not stop delivery to the other.

This guide covers provider account setup, FenPing configuration, destination selection, testing, credential rotation, and common failures.

## Delivery model

FenPing stores one shared set of delivery rules:

- Appliance restarts
- IP conflicts
- Normal-device host-status changes
- Important-device host-status changes
- Normal-device service changes
- Important-device service changes

An event disabled by a shared rule is sent to no provider. Re-enabling a rule does not replay events from the disabled period.

Provider readiness is separate from those rules:

| Provider | Ready when |
| --- | --- |
| Discord | `DISCORD_WEBHOOK_URL` is set. |
| Telegram | `TELEGRAM_BOT_TOKEN` is set and a discovered chat is selected. |

Log in as an administrator, open **Notifications**, then use the **Edit notification delivery** button at the top right to view provider status and edit the shared rules. Guests can view the status and rules but cannot change them.

## Editing the environment

Notification credentials belong in the repository's untracked `.env` file. Do not put real credentials in `env.template`, source files, screenshots, issues, or commits.

If this is a new installation and `.env` does not exist, create it from the template and restrict its permissions:

```bash
cp env.template .env
chmod 600 .env
```

If `.env` already exists, edit it in place. Do not copy the template over an existing installation because that would replace its network and application settings.

After changing a provider variable, apply it with the normal deployment command:

```bash
./fenping.sh restart
```

When testing an unpublished source checkout, use:

```bash
./fenping.sh dev
```

FenPing sends the restart event during startup when the restart rule is enabled. You can also test every ready provider later without restarting:

```bash
docker exec fenping php /opt/fenping/cli.php notify-restart
```

The command reports each provider as `sent`, `skipped`, or `failed`. A skipped provider is either not ready or blocked by the shared restart rule.

## Discord

Discord delivery uses an incoming webhook. A Discord application or bot is not required.

Discord's official [webhook guide](https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks) describes the server-side creation flow.

### 1. Create the webhook

1. Open the Discord server that should receive FenPing alerts.
2. Open **Server Settings**.
3. Select **Integrations**, then **Webhooks**.
4. Create a webhook.
5. Give it a recognizable name such as `FenPing`.
6. Select the destination text channel.
7. Copy the webhook URL.

You need permission to manage webhooks in that server or channel. Treat the copied URL as a password: anyone who has it can post through that webhook.

### 2. Configure FenPing

Add the copied URL to `.env`:

```dotenv
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/WEBHOOK_ID/WEBHOOK_TOKEN
DISCORD_MENTION=
```

Do not append `/github`; that suffix is only for Discord's GitHub-specific webhook format.

Restart FenPing, open the notification editor, and confirm that Discord shows **Configured**.

### 3. Configure an optional mention

`DISCORD_MENTION` applies to every Discord notification. It is not limited to important devices.

To send without a ping:

```dotenv
DISCORD_MENTION=
```

To ping everyone:

```dotenv
DISCORD_MENTION=@everyone
```

To ping one Discord user, use that person's numeric user ID:

```dotenv
DISCORD_MENTION=123456789012345678
```

FenPing also accepts `@123456789012345678` and `<@123456789012345678>`, but the plain numeric form is easiest to audit.

To copy a user ID, enable **Developer Mode** under Discord **User Settings > Advanced**, then right-click the user and choose **Copy User ID**. Discord documents this in [Where can I find my User/Server/Message ID?](https://support.discord.com/hc/en-us/articles/206346498-Where-can-I-find-my-User-Server-Message-ID).

FenPing sends explicit `allowed_mentions` on every webhook request. Messages without a configured target disable mention parsing; configured user or everyone mentions permit only that target. Discord can still suppress a visible ping because of channel permissions or the recipient's notification settings, as noted in its [message mention documentation](https://docs.discord.com/developers/resources/message#allowed-mentions-object).

### 4. Test Discord

Enable **Appliance restarts** in the notification editor, save, then run:

```bash
docker exec fenping php /opt/fenping/cli.php notify-restart
```

The CLI should report `discord restart notification sent`, and the selected Discord channel should receive the message.

### Discord troubleshooting

- **Discord shows Not configured:** verify `DISCORD_WEBHOOK_URL` is in `.env`, then restart FenPing.
- **HTTP 401 or 404:** the webhook URL is incorrect, revoked, or deleted. Create or copy it again.
- **The message arrives but does not ping:** confirm `DISCORD_MENTION`, restart FenPing, check channel mention permissions, and check the user's Discord notification settings.
- **`@everyone` is visible but silent:** ensure the channel permits the webhook to mention everyone and that the receiving user has not suppressed those notifications.
- **A webhook URL was exposed:** delete or regenerate the webhook in Discord immediately, update `.env`, and restart FenPing.

## Telegram

Telegram delivery uses a dedicated bot token. There is no `TELEGRAM_CHAT_ID` environment variable: FenPing discovers chats from Bot API updates and stores the administrator's selection locally.

Telegram's official [bot tutorial](https://core.telegram.org/bots/tutorial) covers creating a bot with BotFather.

### 1. Create the bot

1. In Telegram, open the verified `@BotFather` account.
2. Send `/newbot`.
3. Choose a display name.
4. Choose a unique username ending in `bot`.
5. Copy the generated bot token.

The token controls the bot and must be handled like a password. If it is exposed, use BotFather to revoke it and generate a replacement.

### 2. Configure FenPing

Add only the bot token to `.env`:

```dotenv
TELEGRAM_BOT_TOKEN=123456789:replace-with-the-token-from-botfather
```

Restart FenPing. Telegram will show **Configured**, but delivery remains disabled until a destination is discovered and selected.

### 3. Generate an update from the destination

A Telegram bot cannot start a conversation with a user. The destination must interact with the bot first.

For a private chat:

1. Search for the bot by its username.
2. Open it and press **Start**, or send `/start`.

For a group or supergroup:

1. Add the bot to the group.
2. Send `/start@YourBotUsername` in that group. A command addressed to the bot works even when BotFather privacy mode is enabled.
3. Ensure the bot is allowed to post messages in the group.

For a channel:

1. Add the bot as a channel administrator.
2. Grant permission to post messages.
3. Publish a channel post if adding the bot did not already generate a membership update.

FenPing recognizes private chats, groups, supergroups, and channels. It also records available chat names, usernames, and sender information so the administrator can distinguish destinations.

### 4. Select the destination

1. Log in to FenPing.
2. Open **Notifications**.
3. Open **Edit notification delivery**.
4. In **Telegram destination**, click **Refresh Telegram chats** if the expected chat is not already shown.
5. Select the intended chat.
6. Click **Save notification rules**.

The selection is persisted locally. It is excluded from backups.

### 5. Test Telegram

With the restart rule enabled, run:

```bash
docker exec fenping php /opt/fenping/cli.php notify-restart
```

The CLI should report `telegram restart notification sent`.

### Telegram troubleshooting

- **No chats are discovered:** send a new message or addressed command after the bot is configured, then refresh the list.
- **`getUpdates` fails:** Telegram does not allow long polling while an outgoing webhook is configured. Remove the bot's existing webhook or use a dedicated FenPing bot. See Telegram's [Bot FAQ](https://core.telegram.org/bots/faq#getting-updates).
- **Another service uses the same bot:** two polling consumers can consume each other's updates. Use a bot dedicated to FenPing.
- **A group is missing:** send `/start@YourBotUsername` in that group and refresh.
- **Messages fail in a group or channel:** verify the bot is still a member and can post there.
- **The bot token changed:** FenPing intentionally clears the old known-chat list and selected destination. Generate a fresh update, refresh, and select the chat again.
- **The token was exposed:** revoke it in BotFather, update `.env`, restart, and select the destination again.

## Shared rules and verification

After one or more providers are ready:

1. Open **Notifications**.
2. Click **Edit notification delivery**.
3. Enable or disable **Appliance restarts** and **IP conflicts**.
4. Configure Normal and Important rows independently for host status and service changes.
5. Save once; every provider uses the resulting rules.

A managed device with `important=1` uses the Important row. Unmanaged and unmatched devices use the Normal row. An event belongs to one group and is never delivered twice because of classification.

Review provider delivery failures under **Operations**. Discord and Telegram each have independent health and recent-failure information.

## Disabling or rotating providers

To disable Discord, clear `DISCORD_WEBHOOK_URL`. To disable Telegram, clear `TELEGRAM_BOT_TOKEN`. Restart FenPing after editing `.env`.

Rotating Telegram credentials invalidates the locally selected destination by design. Generate a fresh update, reopen the notification editor, and select the destination again.

No notification credential or Telegram destination is included in FenPing backups. Keep a separate secure record of the credentials needed to rebuild the integration.
