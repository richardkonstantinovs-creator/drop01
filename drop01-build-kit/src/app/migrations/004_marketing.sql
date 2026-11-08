-- 004_marketing: discount codes (admin and referral rewards), abandoned-cart contact + consent, and email broadcasts.

ALTER TABLE discount_codes ADD COLUMN customer_id INTEGER NULL;
ALTER TABLE discount_codes ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'admin';
ALTER TABLE discount_codes ADD COLUMN ref_order_id INTEGER NULL;
ALTER TABLE discount_codes ADD COLUMN note VARCHAR(160) NULL;
ALTER TABLE discount_codes ADD COLUMN created_at VARCHAR(25) NULL;
ALTER TABLE orders ADD COLUMN code_discount_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE carts ADD COLUMN name VARCHAR(160) NULL;
ALTER TABLE carts ADD COLUMN consent INTEGER NOT NULL DEFAULT 0;
ALTER TABLE carts ADD COLUMN ordered_at VARCHAR(25) NULL;
ALTER TABLE messages ADD COLUMN broadcast_id INTEGER NULL;

CREATE TABLE IF NOT EXISTS broadcasts (id {{PK}}, segment VARCHAR(20) NOT NULL, subject VARCHAR(200) NOT NULL, heading VARCHAR(200) NULL, body {{TEXT}},
  button_label VARCHAR(60) NULL, button_url VARCHAR(255) NULL, recipients INTEGER NOT NULL DEFAULT 0, by_admin_id INTEGER NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE INDEX idx_msg_broadcast ON messages (broadcast_id);
CREATE INDEX idx_ac_session ON abandoned_carts (session_id);
CREATE INDEX idx_dc_ref ON discount_codes (ref_order_id);
CREATE INDEX idx_orders_code ON orders (discount_code);
CREATE INDEX idx_orders_ref ON orders (referral_code);
