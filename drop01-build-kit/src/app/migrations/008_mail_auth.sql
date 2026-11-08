-- 008_mail_auth: what the receiving mail server said about an incoming email's sender (SPF / DKIM / DMARC), so spoofed customer emails get a warning in the Inbox.
-- 'pass' = the sender is confirmed, 'fail' = the email claims a sender it can't prove, NULL = not checked (Telegram, older mail, or the server adds no verdict).

ALTER TABLE conversation_messages ADD COLUMN auth VARCHAR(8) NULL;
