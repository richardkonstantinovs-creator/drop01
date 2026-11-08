-- 005_ops: who took cash at the door, cash handovers from couriers to the owner.

ALTER TABLE orders ADD COLUMN cash_by INTEGER NULL;
ALTER TABLE orders ADD COLUMN cash_handover_id INTEGER NULL;

CREATE TABLE IF NOT EXISTS cash_handovers (id {{PK}}, courier_id INTEGER NOT NULL, expected_cents INTEGER NOT NULL, received_cents INTEGER NOT NULL, orders INTEGER NOT NULL DEFAULT 0,
  note VARCHAR(200) NULL, by_admin_id INTEGER NULL, created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE INDEX idx_orders_cash ON orders (cash_by, cash_handover_id);
