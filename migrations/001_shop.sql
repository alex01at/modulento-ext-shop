-- A shop product is a normal offer (title, description, images, category -
-- all already generic); what makes it a product is its variants. Every
-- product has at least one variant, even with nothing to choose - a single
-- "Standard" row. Price and stock live on the variant, never on the offer
-- directly, the same way a freelancer service keeps them on its packages.

CREATE TABLE x_shop_variant (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    sku VARCHAR(64) NULL,
    price INT UNSIGNED NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_x_shop_variant_sku (sku),
    KEY idx_x_shop_variant_offer (offer_id, position),
    CONSTRAINT fk_x_shop_variant_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_shop_variant_translation (
    variant_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    label VARCHAR(150) NOT NULL,
    PRIMARY KEY (variant_id, locale),
    CONSTRAINT fk_x_shop_variant_translation FOREIGN KEY (variant_id) REFERENCES x_shop_variant (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The cart: one row per account and variant, quantity added together on a
-- second "add to cart". Tied to the account, not the session - there is no
-- guest checkout.
CREATE TABLE x_shop_cart_item (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    variant_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    added_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_shop_cart_item (account_id, variant_id),
    CONSTRAINT fk_x_shop_cart_item_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_shop_cart_item_variant FOREIGN KEY (variant_id) REFERENCES x_shop_variant (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
