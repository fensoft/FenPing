ALTER TABLE notification_delivery_settings ADD COLUMN telegram_chat_id TEXT;
ALTER TABLE notification_delivery_settings ADD COLUMN telegram_bot_fingerprint TEXT;

CREATE TABLE telegram_known_chats (
  chat_id TEXT PRIMARY KEY,
  chat_type TEXT NOT NULL,
  chat_title TEXT,
  chat_username TEXT,
  chat_first_name TEXT,
  chat_last_name TEXT,
  user_id TEXT,
  user_is_bot INTEGER CHECK (user_is_bot IS NULL OR user_is_bot IN (0, 1)),
  user_first_name TEXT,
  user_last_name TEXT,
  user_username TEXT,
  user_language_code TEXT,
  last_update_id INTEGER NOT NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
