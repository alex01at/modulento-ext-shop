-- A variant can be digital: no shipping, no stock to run out of, delivered
-- as a download once its order is paid. The file itself lives outside the
-- web root (see Downloads) - these columns only say whether one is there.
ALTER TABLE x_shop_variant
    ADD COLUMN is_digital TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER stock,
    ADD COLUMN file_name VARCHAR(40) NULL AFTER is_digital,
    ADD COLUMN file_original_name VARCHAR(255) NULL AFTER file_name,
    ADD COLUMN file_extension VARCHAR(10) NULL AFTER file_original_name,
    ADD COLUMN file_bytes INT UNSIGNED NULL AFTER file_extension;
