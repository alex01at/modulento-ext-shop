<?php

declare(strict_types=1);

namespace Modulento\Shop;

use PDO;

/** The one discount code an account currently has applied to their cart, if any. */
final class CartDiscount
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{discount_id: int}|null */
    public function find(int $accountId): ?array
    {
        $stmt = $this->db->prepare('SELECT discount_id FROM x_shop_cart_discount WHERE account_id = :account');
        $stmt->execute(['account' => $accountId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? ['discount_id' => (int) $id] : null;
    }

    public function set(int $accountId, int $discountId): void
    {
        $this->remove($accountId);
        $this->db->prepare('INSERT INTO x_shop_cart_discount (account_id, discount_id, applied_at) VALUES (:account, :discount, :now)')
            ->execute(['account' => $accountId, 'discount' => $discountId, 'now' => gmdate('Y-m-d H:i:s')]);
    }

    public function remove(int $accountId): void
    {
        $this->db->prepare('DELETE FROM x_shop_cart_discount WHERE account_id = :account')->execute(['account' => $accountId]);
    }
}
