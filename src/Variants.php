<?php

declare(strict_types=1);

namespace Modulento\Shop;

use PDO;

/**
 * A product's variants: what is actually priced and stocked. Every offer of
 * type "shop.product" has at least one row here, even with nothing to
 * choose - a single "Standard" variant. A variant can be digital, with a
 * file attached through its own route (Downloads) after it exists - saving
 * the product's form must not disturb that file, so save() updates
 * existing rows in place by id instead of replacing them wholesale.
 */
final class Variants
{
    public const MAX = 8;

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array{id: int, position: int, sku: ?string, price: int, stock: int, is_digital: bool, file_name: ?string, file_original_name: ?string, file_extension: ?string, file_bytes: ?int, texts: array<string, array{label: string}>}> ordered by position */
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
            $byId[(int) $row['id']] = self::row($row) + ['texts' => []];
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

    /** @return array{id: int, offer_id: int, sku: ?string, price: int, stock: int, is_digital: bool, file_name: ?string, file_original_name: ?string, file_extension: ?string, file_bytes: ?int}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_shop_variant WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? self::row($row) : null;
    }

    /** Like find(), with its texts too - one query more, only where the label is actually needed. */
    public function findWithTexts(int $id): ?array
    {
        $variant = $this->find($id);
        if ($variant === null) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT locale, label FROM x_shop_variant_translation WHERE variant_id = :id');
        $stmt->execute(['id' => $id]);
        $variant['texts'] = [];
        foreach ($stmt->fetchAll() as $row) {
            $variant['texts'][$row['locale']] = ['label' => $row['label']];
        }

        return $variant;
    }

    /** The variant's label in a language, falling back the same way offer texts do. */
    public function label(array $variant, string $locale, string $default): string
    {
        return $variant['texts'][$locale]['label'] ?? $variant['texts'][$default]['label'] ?? (array_values($variant['texts'])[0]['label'] ?? '');
    }

    /**
     * Saves an offer's variants from its form: a row with an "id" already
     * in the database is updated (its file, if any, stays attached); one
     * without is inserted; an existing row that is not among these any
     * more is removed, its file (if any) deleted too. Called from
     * ProductType::save(), already validated.
     *
     * @param list<array{id: ?int, sku: ?string, price: int, stock: int, is_digital: bool, texts: array<string, string>}> $variants
     * @return int the lowest price, for the offer's own price_from
     */
    public function save(int $offerId, array $variants, Downloads $downloads): int
    {
        $existingIds = array_column($this->forOffer($offerId), 'id');

        $update = $this->db->prepare(
            'UPDATE x_shop_variant SET position = :position, sku = :sku, price = :price, stock = :stock, is_digital = :digital WHERE id = :id AND offer_id = :offer'
        );
        $insert = $this->db->prepare(
            'INSERT INTO x_shop_variant (offer_id, position, sku, price, stock, is_digital) VALUES (:offer, :position, :sku, :price, :stock, :digital)'
        );
        $deleteText = $this->db->prepare('DELETE FROM x_shop_variant_translation WHERE variant_id = :id');
        $insertText = $this->db->prepare(
            'INSERT INTO x_shop_variant_translation (variant_id, locale, label) VALUES (:variant, :locale, :label)'
        );

        $prices = [];
        $keptIds = [];
        foreach (array_values($variants) as $position => $variant) {
            $digital = $variant['is_digital'] ? 1 : 0;
            if ($variant['id'] !== null && in_array($variant['id'], $existingIds, true)) {
                $id = $variant['id'];
                $update->execute(['position' => $position, 'sku' => $variant['sku'], 'price' => $variant['price'], 'stock' => $variant['stock'], 'digital' => $digital, 'id' => $id, 'offer' => $offerId]);
            } else {
                $insert->execute(['offer' => $offerId, 'position' => $position, 'sku' => $variant['sku'], 'price' => $variant['price'], 'stock' => $variant['stock'], 'digital' => $digital]);
                $id = (int) $this->db->lastInsertId();
            }
            $keptIds[] = $id;

            $deleteText->execute(['id' => $id]);
            foreach ($variant['texts'] as $locale => $label) {
                $insertText->execute(['variant' => $id, 'locale' => $locale, 'label' => $label]);
            }
            $prices[] = $variant['price'];
        }

        foreach (array_diff($existingIds, $keptIds) as $removedId) {
            $downloads->removeFile($removedId);
            $this->db->prepare('DELETE FROM x_shop_variant WHERE id = :id')->execute(['id' => $removedId]);
        }

        return min($prices);
    }

    /**
     * Takes stock for an order, only where enough is left - the guard is in
     * the UPDATE itself, so two buyers racing for the last piece cannot both
     * succeed. A digital variant has nothing to run out of.
     */
    public function reserve(int $variantId, int $quantity): bool
    {
        $stmt = $this->db->prepare('UPDATE x_shop_variant SET stock = stock - :qty WHERE id = :id AND is_digital = 0 AND stock >= :qty2');
        $stmt->execute(['qty' => $quantity, 'id' => $variantId, 'qty2' => $quantity]);

        return $stmt->rowCount() === 1 || $this->isDigital($variantId);
    }

    /** Gives stock back, e.g. when an order is cancelled. A digital variant needs nothing given back. */
    public function release(int $variantId, int $quantity): void
    {
        $this->db->prepare('UPDATE x_shop_variant SET stock = stock + :qty WHERE id = :id AND is_digital = 0')->execute(['qty' => $quantity, 'id' => $variantId]);
    }

    public function isDigital(int $variantId): bool
    {
        $stmt = $this->db->prepare('SELECT is_digital FROM x_shop_variant WHERE id = :id');
        $stmt->execute(['id' => $variantId]);

        return (bool) $stmt->fetchColumn();
    }

    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'offer_id' => (int) $row['offer_id'], 'position' => (int) $row['position'], 'sku' => $row['sku'],
            'price' => (int) $row['price'], 'stock' => (int) $row['stock'], 'is_digital' => (bool) $row['is_digital'],
            'file_name' => $row['file_name'], 'file_original_name' => $row['file_original_name'],
            'file_extension' => $row['file_extension'], 'file_bytes' => $row['file_bytes'] !== null ? (int) $row['file_bytes'] : null,
        ];
    }
}
