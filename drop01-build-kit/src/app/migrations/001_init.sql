-- 001_init: the full schema from CLAUDE.md §3.2. Money is integer cents, timestamps are ISO-8601 UTC text.

CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY, applied_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v {{TEXT}}){{ENGINE}};

CREATE TABLE IF NOT EXISTS admins (
  id {{PK}}, name VARCHAR(120) NOT NULL, email VARCHAR(191) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'owner', totp_secret VARCHAR(64) NULL, active INTEGER NOT NULL DEFAULT 1,
  created_at VARCHAR(25) NOT NULL, last_login_at VARCHAR(25) NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS login_attempts (id {{PK}}, email VARCHAR(191), ip_hash VARCHAR(64), ok INTEGER NOT NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};
CREATE INDEX idx_login_email ON login_attempts (email, created_at);
CREATE INDEX idx_login_ip ON login_attempts (ip_hash, created_at);

CREATE TABLE IF NOT EXISTS rate_limits (k VARCHAR(191) PRIMARY KEY, window_start INTEGER NOT NULL, hits INTEGER NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS products (
  id {{PK}}, slug VARCHAR(120) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, description {{TEXT}},
  price_cents INTEGER NOT NULL DEFAULT 0, cost_cents INTEGER NOT NULL DEFAULT 0, vat_rate INTEGER NOT NULL DEFAULT 21,
  category VARCHAR(80) NULL, featured INTEGER NOT NULL DEFAULT 0, reveal INTEGER NOT NULL DEFAULT 0,
  on_waitlist INTEGER NOT NULL DEFAULT 1, note VARCHAR(160) NULL, sort INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
  run_size INTEGER NOT NULL DEFAULT 0, low_stock_at INTEGER NOT NULL DEFAULT 5, is_demo INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS product_images (id {{PK}}, product_id INTEGER NOT NULL, path VARCHAR(255) NOT NULL, path_sm VARCHAR(255) NULL, w INTEGER NULL, h INTEGER NULL, sort INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE){{ENGINE}};

CREATE TABLE IF NOT EXISTS variants (id {{PK}}, product_id INTEGER NOT NULL, label VARCHAR(80) NOT NULL DEFAULT 'Default', color_hex VARCHAR(9) NULL, sku VARCHAR(80) NULL,
  stock INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE){{ENGINE}};

CREATE TABLE IF NOT EXISTS bundles (id {{PK}}, name VARCHAR(120) NOT NULL, discount_pct INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1){{ENGINE}};
CREATE TABLE IF NOT EXISTS bundle_items (bundle_id INTEGER NOT NULL, product_id INTEGER NOT NULL, PRIMARY KEY (bundle_id, product_id)){{ENGINE}};

CREATE TABLE IF NOT EXISTS customers (id {{PK}}, name VARCHAR(160) NULL, email VARCHAR(191) NULL, phone VARCHAR(32) NULL, source VARCHAR(40) NULL,
  marketing_consent INTEGER NOT NULL DEFAULT 0, consent_at VARCHAR(25) NULL, created_at VARCHAR(25) NOT NULL, notes {{TEXT}}, is_demo INTEGER NOT NULL DEFAULT 0){{ENGINE}};
CREATE INDEX idx_cust_email ON customers (email);
CREATE INDEX idx_cust_phone ON customers (phone);

CREATE TABLE IF NOT EXISTS addresses (id {{PK}}, customer_id INTEGER NULL, postcode VARCHAR(8) NOT NULL, house_no VARCHAR(10) NOT NULL, addition VARCHAR(10) NULL,
  street VARCHAR(160) NULL, city VARCHAR(80) NULL, lat REAL NULL, lng REAL NULL, zone_id INTEGER NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS waitlist_signups (id {{PK}}, email VARCHAR(191) NOT NULL UNIQUE, phone VARCHAR(32) NOT NULL, country VARCHAR(4) NULL,
  gift_code VARCHAR(20) NULL UNIQUE, gift_redeemed_order_id INTEGER NULL, position INTEGER NOT NULL, source VARCHAR(40) NULL, device VARCHAR(20) NULL,
  utm {{TEXT}}, referrer VARCHAR(255) NULL, likes {{TEXT}}, consent INTEGER NOT NULL DEFAULT 0, consent_text VARCHAR(255) NULL,
  unsubscribed_at VARCHAR(25) NULL, created_at VARCHAR(25) NOT NULL, is_demo INTEGER NOT NULL DEFAULT 0){{ENGINE}};
CREATE INDEX idx_wl_phone ON waitlist_signups (phone);
CREATE INDEX idx_wl_created ON waitlist_signups (created_at);

CREATE TABLE IF NOT EXISTS votes (visitor_id VARCHAR(64) NOT NULL, product_id INTEGER NOT NULL, value INTEGER NOT NULL, updated_at VARCHAR(25) NOT NULL,
  PRIMARY KEY (visitor_id, product_id)){{ENGINE}};
CREATE INDEX idx_votes_product ON votes (product_id);

CREATE TABLE IF NOT EXISTS restock_requests (id {{PK}}, product_id INTEGER NOT NULL, email VARCHAR(191) NULL, phone VARCHAR(32) NULL, created_at VARCHAR(25) NOT NULL, notified_at VARCHAR(25) NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS delivery_zones (id {{PK}}, name VARCHAR(80) NOT NULL, postcode_from INTEGER NOT NULL, postcode_to INTEGER NOT NULL,
  fee_cents INTEGER NOT NULL DEFAULT 0, free_from_cents INTEGER NULL, sort INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1){{ENGINE}};

CREATE TABLE IF NOT EXISTS delivery_slots (id {{PK}}, date VARCHAR(10) NOT NULL, start_time VARCHAR(5) NOT NULL, end_time VARCHAR(5) NOT NULL,
  capacity INTEGER NOT NULL DEFAULT 10, booked INTEGER NOT NULL DEFAULT 0, closed INTEGER NOT NULL DEFAULT 0){{ENGINE}};
CREATE UNIQUE INDEX idx_slot_unique ON delivery_slots (date, start_time);

CREATE TABLE IF NOT EXISTS orders (id {{PK}}, number INTEGER NOT NULL UNIQUE, token VARCHAR(40) NOT NULL, customer_id INTEGER NULL, address_id INTEGER NULL, slot_id INTEGER NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'new', payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid', payment_method VARCHAR(20) NULL,
  subtotal_cents INTEGER NOT NULL DEFAULT 0, discount_cents INTEGER NOT NULL DEFAULT 0, delivery_cents INTEGER NOT NULL DEFAULT 0,
  total_cents INTEGER NOT NULL DEFAULT 0, vat_cents INTEGER NOT NULL DEFAULT 0, gift_code VARCHAR(20) NULL, discount_code VARCHAR(40) NULL, referral_code VARCHAR(40) NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'web', needs_new_slot INTEGER NOT NULL DEFAULT 0, route_pos INTEGER NULL, notes {{TEXT}},
  created_at VARCHAR(25) NOT NULL, paid_at VARCHAR(25) NULL, delivered_at VARCHAR(25) NULL, expires_at VARCHAR(25) NULL, is_demo INTEGER NOT NULL DEFAULT 0){{ENGINE}};
CREATE INDEX idx_orders_status ON orders (status, created_at);

CREATE TABLE IF NOT EXISTS order_items (id {{PK}}, order_id INTEGER NOT NULL, product_id INTEGER NULL, variant_id INTEGER NULL, name_snapshot VARCHAR(200) NOT NULL,
  unit_price_cents INTEGER NOT NULL, unit_cost_cents INTEGER NOT NULL DEFAULT 0, qty INTEGER NOT NULL, vat_rate INTEGER NOT NULL DEFAULT 21,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE){{ENGINE}};

CREATE TABLE IF NOT EXISTS order_events (id {{PK}}, order_id INTEGER NOT NULL, type VARCHAR(40) NOT NULL, data {{TEXT}}, by_admin_id INTEGER NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};
CREATE INDEX idx_oe_order ON order_events (order_id);

CREATE TABLE IF NOT EXISTS payments (id {{PK}}, order_id INTEGER NOT NULL, provider VARCHAR(20) NOT NULL, provider_id VARCHAR(80) NULL, status VARCHAR(20) NOT NULL,
  amount_cents INTEGER NOT NULL, method VARCHAR(30) NULL, raw {{TEXT}}, created_at VARCHAR(25) NOT NULL, updated_at VARCHAR(25) NOT NULL){{ENGINE}};
CREATE INDEX idx_pay_provider ON payments (provider_id);

CREATE TABLE IF NOT EXISTS returns (id {{PK}}, order_id INTEGER NOT NULL, reason {{TEXT}}, items {{TEXT}}, resolution VARCHAR(20) NULL, amount_cents INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'open', created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS invoices (id {{PK}}, order_id INTEGER NOT NULL, number VARCHAR(20) NOT NULL UNIQUE, pdf_path VARCHAR(255) NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS suppliers (id {{PK}}, name VARCHAR(160) NOT NULL, contact VARCHAR(160) NULL, email VARCHAR(191) NULL, phone VARCHAR(32) NULL, lead_days INTEGER NOT NULL DEFAULT 7, notes {{TEXT}}){{ENGINE}};
CREATE TABLE IF NOT EXISTS purchase_orders (id {{PK}}, supplier_id INTEGER NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', expected_at VARCHAR(25) NULL, total_cents INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(25) NOT NULL){{ENGINE}};
CREATE TABLE IF NOT EXISTS po_items (po_id INTEGER NOT NULL, variant_id INTEGER NOT NULL, qty INTEGER NOT NULL, unit_cost_cents INTEGER NOT NULL, PRIMARY KEY (po_id, variant_id)){{ENGINE}};

CREATE TABLE IF NOT EXISTS stock_movements (id {{PK}}, variant_id INTEGER NOT NULL, delta INTEGER NOT NULL, reason VARCHAR(20) NOT NULL, ref VARCHAR(80) NULL,
  by_admin_id INTEGER NULL, expires_at VARCHAR(25) NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};
CREATE INDEX idx_sm_variant ON stock_movements (variant_id, created_at);

CREATE TABLE IF NOT EXISTS expenses (id {{PK}}, date VARCHAR(10) NOT NULL, category VARCHAR(20) NOT NULL, amount_cents INTEGER NOT NULL, vat_cents INTEGER NOT NULL DEFAULT 0, note {{TEXT}}, receipt_path VARCHAR(255) NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS discount_codes (id {{PK}}, code VARCHAR(40) NOT NULL UNIQUE, type VARCHAR(20) NOT NULL, value INTEGER NOT NULL DEFAULT 0, min_cents INTEGER NOT NULL DEFAULT 0,
  max_uses INTEGER NULL, used INTEGER NOT NULL DEFAULT 0, starts_at VARCHAR(25) NULL, ends_at VARCHAR(25) NULL, active INTEGER NOT NULL DEFAULT 1){{ENGINE}};
CREATE TABLE IF NOT EXISTS referral_codes (id {{PK}}, customer_id INTEGER NOT NULL, code VARCHAR(40) NOT NULL UNIQUE, uses INTEGER NOT NULL DEFAULT 0){{ENGINE}};
CREATE TABLE IF NOT EXISTS abandoned_carts (id {{PK}}, session_id VARCHAR(64) NOT NULL, email VARCHAR(191) NULL, phone VARCHAR(32) NULL, cart {{TEXT}}, reminded_at VARCHAR(25) NULL, recovered_order_id INTEGER NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS events (id {{PK}}, ts VARCHAR(25) NOT NULL, day VARCHAR(10) NOT NULL, visitor_hash VARCHAR(64) NOT NULL, session_id VARCHAR(40) NOT NULL,
  type VARCHAR(30) NOT NULL, path VARCHAR(200) NULL, ref_host VARCHAR(120) NULL, source VARCHAR(20) NULL, utm_source VARCHAR(80) NULL, utm_medium VARCHAR(80) NULL,
  utm_campaign VARCHAR(80) NULL, device VARCHAR(20) NULL, country VARCHAR(4) NULL, product_id INTEGER NULL, value VARCHAR(80) NULL){{ENGINE}};
CREATE INDEX idx_ev_day ON events (day, type);
CREATE INDEX idx_ev_ts ON events (ts);
CREATE INDEX idx_ev_session ON events (session_id);

CREATE TABLE IF NOT EXISTS events_daily (day VARCHAR(10) NOT NULL, type VARCHAR(30) NOT NULL, source VARCHAR(20) NOT NULL DEFAULT '', device VARCHAR(20) NOT NULL DEFAULT '',
  country VARCHAR(4) NOT NULL DEFAULT '', sessions INTEGER NOT NULL DEFAULT 0, visitors INTEGER NOT NULL DEFAULT 0, n INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (day, type, source, device, country)){{ENGINE}};

CREATE TABLE IF NOT EXISTS daily_salts (day VARCHAR(10) PRIMARY KEY, salt VARCHAR(64) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS messages (id {{PK}}, customer_id INTEGER NULL, order_id INTEGER NULL, signup_id INTEGER NULL, channel VARCHAR(10) NOT NULL, template VARCHAR(40) NOT NULL,
  to_addr VARCHAR(191) NOT NULL, subject VARCHAR(200) NULL, body {{TEXT}}, status VARCHAR(20) NOT NULL DEFAULT 'queued', tries INTEGER NOT NULL DEFAULT 0, error {{TEXT}}, created_at VARCHAR(25) NOT NULL, sent_at VARCHAR(25) NULL){{ENGINE}};
CREATE INDEX idx_msg_status ON messages (status);

CREATE TABLE IF NOT EXISTS audit_log (id {{PK}}, admin_id INTEGER NULL, action VARCHAR(60) NOT NULL, entity VARCHAR(40) NULL, entity_id VARCHAR(40) NULL, data {{TEXT}}, created_at VARCHAR(25) NOT NULL){{ENGINE}};
