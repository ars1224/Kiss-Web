ALTER TABLE orders
    ADD COLUMN order_comments TEXT DEFAULT NULL
    AFTER min_shelf_life_months;
