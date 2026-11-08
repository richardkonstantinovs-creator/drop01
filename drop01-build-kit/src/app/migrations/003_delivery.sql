-- 003_delivery: routes per window (courier, manual order; orders.route_pos is in 001), failed attempts and photo proof.

ALTER TABLE delivery_slots ADD COLUMN courier_id INTEGER NULL;
ALTER TABLE delivery_slots ADD COLUMN route_manual INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN failed_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN proof_path VARCHAR(255) NULL;
ALTER TABLE orders ADD COLUMN out_at VARCHAR(25) NULL;
