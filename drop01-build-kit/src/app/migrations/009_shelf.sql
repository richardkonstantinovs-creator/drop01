-- 009_shelf: where each variant lies in the stock room (e.g. A1, B3), so the packing screen can say where to pick from.

ALTER TABLE variants ADD COLUMN shelf VARCHAR(20) NULL;
