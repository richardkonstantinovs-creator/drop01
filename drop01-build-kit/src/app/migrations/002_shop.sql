-- 002_shop: server carts, address cache, "notify me for the next drop" list, order payment links and slot holds.

CREATE TABLE IF NOT EXISTS carts (id VARCHAR(40) PRIMARY KEY, items {{TEXT}}, email VARCHAR(191) NULL, phone VARCHAR(32) NULL, updated_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS address_cache (k VARCHAR(40) PRIMARY KEY, data {{TEXT}}, created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS list_signups (id {{PK}}, email VARCHAR(191) NOT NULL UNIQUE, source VARCHAR(40) NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};

ALTER TABLE orders ADD COLUMN pay_url VARCHAR(255) NULL;
ALTER TABLE orders ADD COLUMN tikkie_url VARCHAR(255) NULL;
ALTER TABLE orders ADD COLUMN marketing_consent INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN reserved INTEGER NOT NULL DEFAULT 0;

CREATE INDEX idx_orders_slot ON orders (slot_id, status);
CREATE INDEX idx_orders_customer ON orders (customer_id);
CREATE INDEX idx_wl_gift ON waitlist_signups (gift_code);
CREATE INDEX idx_restock_product ON restock_requests (product_id);
