-- Snapshot the saved pin's landmark note onto the order so the vendor sees it
-- after accepting, even if the customer later edits or deletes the location.
ALTER TABLE orders ADD COLUMN delivery_landmark VARCHAR(255) NULL AFTER delivery_alias;
