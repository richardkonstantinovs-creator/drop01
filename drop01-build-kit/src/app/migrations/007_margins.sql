-- 007_margins: packaging materials (bought in packs) and how many of each a product uses, for the unit economics on the Money screen.
-- per = 'order': goes into every order once (shipping bag, card); per = 'item': assigned to products in product_materials.

CREATE TABLE IF NOT EXISTS materials (id {{PK}}, name VARCHAR(120) NOT NULL, pack_cents INTEGER NOT NULL DEFAULT 0, pack_qty INTEGER NOT NULL DEFAULT 1,
  per VARCHAR(10) NOT NULL DEFAULT 'item', active INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(25) NOT NULL){{ENGINE}};

CREATE TABLE IF NOT EXISTS product_materials (product_id INTEGER NOT NULL, material_id INTEGER NOT NULL, qty DECIMAL(8,2) NOT NULL DEFAULT 1, PRIMARY KEY (product_id, material_id)){{ENGINE}};
