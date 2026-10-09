-- A discount code: either a flat amount off or a percentage off the
-- product subtotal (never off shipping). Usage limits and an expiry are
-- optional; used_count is taken the same atomic way stock is, so two
-- buyers racing for the last use of a limited code cannot both succeed.
CREATE TABLE x_shop_discount (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    type ENUM('flat', 'percent') NOT NULL,
    -- Flat: minor units of the shop's currency. Percent: 1-100.
    value INT UNSIGNED NOT NULL,
    active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NULL,
    max_uses INT UNSIGNED NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    min_subtotal INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_shop_discount_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The code an account currently has applied to their own cart - one at a
-- time, replaced by applying a different one.
CREATE TABLE x_shop_cart_discount (
    account_id INT UNSIGNED NOT NULL PRIMARY KEY,
    discount_id INT UNSIGNED NOT NULL,
    applied_at DATETIME NOT NULL,
    CONSTRAINT fk_x_shop_cart_discount_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_shop_cart_discount_discount FOREIGN KEY (discount_id) REFERENCES x_shop_discount (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
