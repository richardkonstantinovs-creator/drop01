-- 006_inbox: one inbox for customer chats. A conversation is one person on one channel (email address or Telegram chat);
-- its messages are what they wrote and what the team replied (replies are also logged in `messages`).

CREATE TABLE IF NOT EXISTS conversations (id {{PK}}, channel VARCHAR(10) NOT NULL, address VARCHAR(191) NOT NULL, name VARCHAR(160) NULL, handle VARCHAR(80) NULL,
  customer_id INTEGER NULL, subject VARCHAR(200) NULL, unread INTEGER NOT NULL DEFAULT 0, last_at VARCHAR(25) NOT NULL, last_text VARCHAR(200) NULL, last_dir VARCHAR(3) NULL,
  created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS conversation_messages (id {{PK}}, conversation_id INTEGER NOT NULL, direction VARCHAR(3) NOT NULL, body {{TEXT}}, files {{TEXT}},
  admin_id INTEGER NULL, message_id INTEGER NULL, ext_id VARCHAR(191) NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE UNIQUE INDEX idx_conv_addr ON conversations (channel, address);
CREATE INDEX idx_conv_last ON conversations (last_at);
CREATE INDEX idx_conv_customer ON conversations (customer_id);
CREATE INDEX idx_cm_conv ON conversation_messages (conversation_id, id);
CREATE INDEX idx_cm_ext ON conversation_messages (ext_id);
CREATE INDEX idx_msg_to ON messages (to_addr);
