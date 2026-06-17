ALTER TABLE orders
    ADD COLUMN min_shelf_life_months TINYINT UNSIGNED NOT NULL DEFAULT 6
    AFTER rounding_mode;
