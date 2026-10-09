<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferImages;
use PDO;

/**
 * The cart: variants an account picked, not yet paid for. Tied to the
 * account (no guest checkout), so it is the same on every device. Adding
 * stock is only truly reserved at checkout (Variants::reserve()) - what is
 * checked here is a courtesy so the cart does not silently promise more
 * than is on the shelf.
 */
final class Cart
{
    private const MAX_QUANTITY = 99;

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return list<array{id: int, variant_id: int, offer_id: int, quantity: int, unit_price: int, line_total: int, stock: int, title: string, label: string, path: string, thumb: ?string, currency: string}>
     */
    public function items(int $accountId, App $app): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.quantity, v.id AS variant_id, v.price, v.stock, v.offer_id,
                    o.currency, t.title, t.slug,
                    i.id AS image_id, i.name AS image_name, i.extension AS image_extension, i.width AS image_width, i.height AS image_height
             FROM x_shop_cart_item c
             JOIN x_shop_variant v ON v.id = c.variant_id
             JOIN offer o ON o.id = v.offer_id
             JOIN offer_translation t ON t.offer_id = o.id AND t.locale = :locale
             LEFT JOIN offer_image i ON i.offer_id = o.id AND i.position = 0
             WHERE c.account_id = :account
             ORDER BY c.id'
        );
        $stmt->execute(['locale' => $app->translator->locale(), 'account' => $accountId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $variantsService = new Variants($this->db);
        $default = $app->locales->default();

        $byOffer = [];
        foreach ($rows as $row) {
            $byOffer[(int) $row['offer_id']][] = $row;
        }
        $variantTexts = [];
        foreach (array_keys($byOffer) as $offerId) {
            foreach ($variantsService->forOffer($offerId) as $variant) {
                $variantTexts[$variant['id']] = $variant;
            }
        }

        return array_map(function (array $row) use ($variantTexts, $variantsService, $app, $default) {
            $variant = $variantTexts[(int) $row['variant_id']] ?? ['texts' => []];
            $quantity = (int) $row['quantity'];
            $price = (int) $row['price'];

            return [
                'id' => (int) $row['id'],
                'variant_id' => (int) $row['variant_id'],
                'offer_id' => (int) $row['offer_id'],
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => $price * $quantity,
                'stock' => (int) $row['stock'],
                'title' => $row['title'],
                'label' => $variantsService->label($variant, $app->translator->locale(), $default),
                'path' => '/offers/' . $row['slug'],
                'thumb' => $row['image_id'] !== null ? OfferImages::urls([
                    'id' => $row['image_id'], 'offer_id' => $row['offer_id'], 'name' => $row['image_name'],
                    'extension' => $row['image_extension'], 'width' => $row['image_width'], 'height' => $row['image_height'],
                ])['thumb'] : null,
                'currency' => $row['currency'],
            ];
        }, $rows);
    }

    public function count(int $accountId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM x_shop_cart_item WHERE account_id = :account');
        $stmt->execute(['account' => $accountId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return string|null a language key of the problem, null on success */
    public function add(int $accountId, int $variantId, int $quantity): ?string
    {
        $variant = (new Variants($this->db))->find($variantId);
        if ($variant === null) {
            return 'shop.cart.error.gone';
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            return 'shop.cart.error.quantity';
        }

        $stmt = $this->db->prepare('SELECT quantity FROM x_shop_cart_item WHERE account_id = :account AND variant_id = :variant');
        $stmt->execute(['account' => $accountId, 'variant' => $variantId]);
        $existing = $stmt->fetchColumn();
        $wanted = ($existing !== false ? (int) $existing : 0) + $quantity;

        if ($wanted > $variant['stock']) {
            return 'shop.cart.error.stock';
        }

        if ($existing !== false) {
            $this->db->prepare('UPDATE x_shop_cart_item SET quantity = :qty WHERE account_id = :account AND variant_id = :variant')
                ->execute(['qty' => $wanted, 'account' => $accountId, 'variant' => $variantId]);
        } else {
            $this->db->prepare('INSERT INTO x_shop_cart_item (account_id, variant_id, quantity, added_at) VALUES (:account, :variant, :qty, :now)')
                ->execute(['account' => $accountId, 'variant' => $variantId, 'qty' => $wanted, 'now' => gmdate('Y-m-d H:i:s')]);
        }

        return null;
    }

    /** Setting quantity to 0 removes the line. */
    public function setQuantity(int $accountId, int $cartItemId, int $quantity): void
    {
        if ($quantity < 1) {
            $this->remove($accountId, $cartItemId);
            return;
        }

        $this->db->prepare('UPDATE x_shop_cart_item SET quantity = :qty WHERE id = :id AND account_id = :account')
            ->execute(['qty' => min($quantity, self::MAX_QUANTITY), 'id' => $cartItemId, 'account' => $accountId]);
    }

    public function remove(int $accountId, int $cartItemId): void
    {
        $this->db->prepare('DELETE FROM x_shop_cart_item WHERE id = :id AND account_id = :account')
            ->execute(['id' => $cartItemId, 'account' => $accountId]);
    }

    /** @param int[] $cartItemIds */
    public function removeMany(int $accountId, array $cartItemIds): void
    {
        if ($cartItemIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($cartItemIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM x_shop_cart_item WHERE account_id = ? AND id IN ({$placeholders})");
        $stmt->execute([$accountId, ...$cartItemIds]);
    }
}
