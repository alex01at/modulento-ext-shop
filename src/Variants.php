<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\App;
use PDO;

/**
 * A product's variants: what is actually priced and stocked. Every offer of
 * type "shop.product" has at least one row here, even with nothing to
 * choose - a single "Standard" variant.
 */
final class Variants
{
    public const MAX = 8;

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array{id: int, position: int, sku: ?string, price: int, stock: int, texts: array<string, array{label: string}>}> ordered by position */
    public function forOffer(int $offerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_shop_variant WHERE offer_id = :id ORDER BY position');
        $stmt->execute(['id' => $offerId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = [
                'id' => (int) $row['id'], 'position' => (int) $row['position'], 'sku' => $row['sku'],
                'price' => (int) $row['price'], 'stock' => (int) $row['stock'], 'texts' => [],
            ];
        }

        $stmt = $this->db->prepare(
            'SELECT t.* FROM x_shop_variant_translation t JOIN x_shop_variant v ON v.id = t.variant_id WHERE v.offer_id = :id'
        );
        $stmt->execute(['id' => $offerId]);
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['variant_id']]['texts'][$row['locale']] = ['label' => $row['label']];
        }

        return array_values($byId);
    }

    /** @return array{id: int, offer_id: int, sku: ?string, price: int, stock: int}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, offer_id, sku, price, stock FROM x_shop_variant WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? ['id' => (int) $row['id'], 'offer_id' => (int) $row['offer_id'], 'sku' => $row['sku'], 'price' => (int) $row['price'], 'stock' => (int) $row['stock']] : null;
    }

    /** The variant's label in a language, falling back the same way offer texts do. */
    public function label(array $variant, string $locale, string $default): string
    {
        return $variant['texts'][$locale]['label'] ?? $variant['texts'][$default]['label'] ?? (array_values($variant['texts'])[0]['label'] ?? '');
    }

    /**
     * Replaces every variant of an offer with these. Called from
     * ProductType::save(), already validated.
     *
     * @param list<array{sku: ?string, price: int, stock: int, texts: array<string, string>}> $variants
     * @return int the lowest price, for the offer's own price_from
     */
    public function save(int $offerId, array $variants): int
    {
        $this->db->prepare('DELETE FROM x_shop_variant WHERE offer_id = :id')->execute(['id' => $offerId]);

        $insert = $this->db->prepare(
            'INSERT INTO x_shop_variant (offer_id, position, sku, price, stock) VALUES (:offer, :position, :sku, :price, :stock)'
        );
        $insertText = $this->db->prepare(
            'INSERT INTO x_shop_variant_translation (variant_id, locale, label) VALUES (:variant, :locale, :label)'
        );

        $prices = [];
        foreach (array_values($variants) as $position => $variant) {
            $insert->execute([
                'offer' => $offerId, 'position' => $position, 'sku' => $variant['sku'],
                'price' => $variant['price'], 'stock' => $variant['stock'],
            ]);
            $variantId = (int) $this->db->lastInsertId();
            foreach ($variant['texts'] as $locale => $label) {
                $insertText->execute(['variant' => $variantId, 'locale' => $locale, 'label' => $label]);
            }
            $prices[] = $variant['price'];
        }

        return min($prices);
    }

    /**
     * Takes stock for an order, only where enough is left - the guard is in
     * the UPDATE itself, so two buyers racing for the last piece cannot both
     * succeed.
     */
    public function reserve(int $variantId, int $quantity): bool
    {
        $stmt = $this->db->prepare('UPDATE x_shop_variant SET stock = stock - :qty WHERE id = :id AND stock >= :qty2');
        $stmt->execute(['qty' => $quantity, 'id' => $variantId, 'qty2' => $quantity]);

        return $stmt->rowCount() === 1;
    }

    /** Gives stock back, e.g. when an order is cancelled. */
    public function release(int $variantId, int $quantity): void
    {
        $this->db->prepare('UPDATE x_shop_variant SET stock = stock + :qty WHERE id = :id')->execute(['qty' => $quantity, 'id' => $variantId]);
    }
}
