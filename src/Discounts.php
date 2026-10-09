<?php

declare(strict_types=1);

namespace Modulento\Shop;

use PDO;

/**
 * Discount codes: a flat amount or a percentage off the product subtotal,
 * never off shipping. Usage is taken atomically at checkout
 * (CheckoutController), the same way Variants takes stock, so a code's
 * last use cannot go to two buyers racing for it.
 */
final class Discounts
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{id: int, code: string, type: string, value: int, active: bool, expires_at: ?string, max_uses: ?int, used_count: int, min_subtotal: ?int}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_shop_discount WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? self::typed($row) : null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_shop_discount WHERE code = :code');
        $stmt->execute(['code' => mb_strtoupper(trim($code))]);
        $row = $stmt->fetch();

        return $row !== false ? self::typed($row) : null;
    }

    /** @return list<array> newest first */
    public function all(): array
    {
        return array_map([self::class, 'typed'], $this->db->query('SELECT * FROM x_shop_discount ORDER BY id DESC')->fetchAll());
    }

    /**
     * Whether a discount can currently be applied to a cart with this
     * subtotal (minor units, before shipping).
     *
     * @return string|null a language key of the problem, null if it applies
     */
    public function problem(array $discount, int $subtotal): ?string
    {
        if (!$discount['active']) {
            return 'shop.discount.error.inactive';
        }
        if ($discount['expires_at'] !== null && $discount['expires_at'] < gmdate('Y-m-d H:i:s')) {
            return 'shop.discount.error.expired';
        }
        if ($discount['max_uses'] !== null && $discount['used_count'] >= $discount['max_uses']) {
            return 'shop.discount.error.used_up';
        }
        if ($discount['min_subtotal'] !== null && $subtotal < $discount['min_subtotal']) {
            return 'shop.discount.error.min_subtotal';
        }

        return null;
    }

    /** The amount taken off (minor units), never more than the subtotal itself. */
    public function amount(array $discount, int $subtotal): int
    {
        $raw = $discount['type'] === 'percent' ? (int) round($subtotal * $discount['value'] / 100) : $discount['value'];

        return min($raw, $subtotal);
    }

    /**
     * Prorates a discount amount across cart lines, by each line's share of
     * the subtotal, and returns the lines with their price reduced - split
     * into two rows where a line's own share does not divide evenly by its
     * quantity, so the total is exact to the cent without ever needing a
     * negative price (order_item.unit_price is unsigned).
     *
     * @param list<array{label: string, quantity: int, unit_price: int}> $lines
     * @return list<array{label: string, quantity: int, unit_price: int}>
     */
    public function apply(array $lines, int $discountAmount): array
    {
        if ($discountAmount <= 0 || $lines === []) {
            return $lines;
        }

        $subtotal = array_sum(array_map(fn (array $l) => $l['quantity'] * $l['unit_price'], $lines));
        if ($subtotal <= 0) {
            return $lines;
        }

        $result = [];
        $remaining = $discountAmount;
        $lastIndex = count($lines) - 1;
        foreach ($lines as $index => $line) {
            $lineTotal = $line['quantity'] * $line['unit_price'];
            // The last line absorbs whatever rounding left over, so the
            // lines sum to exactly subtotal - discountAmount.
            $reduction = $index === $lastIndex ? $remaining : min($remaining, (int) floor($lineTotal * $discountAmount / $subtotal));
            $remaining -= $reduction;
            $newTotal = $lineTotal - $reduction;

            if ($reduction === 0) {
                $result[] = $line;
            } elseif ($newTotal % $line['quantity'] === 0) {
                $result[] = ['label' => $line['label'], 'quantity' => $line['quantity'], 'unit_price' => intdiv($newTotal, $line['quantity'])];
            } else {
                // Does not divide evenly: one unit carries the remainder,
                // the rest stay at the original price.
                $fullUnits = $line['quantity'] - 1;
                $result[] = ['label' => $line['label'], 'quantity' => $fullUnits, 'unit_price' => $line['unit_price']];
                $result[] = ['label' => $line['label'], 'quantity' => 1, 'unit_price' => $newTotal - $fullUnits * $line['unit_price']];
            }
        }

        return $result;
    }

    /** Takes one use, only where one is left - the guard is in the UPDATE itself. */
    public function reserve(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE x_shop_discount SET used_count = used_count + 1 WHERE id = :id AND (max_uses IS NULL OR used_count < max_uses)');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function release(int $id): void
    {
        $this->db->prepare('UPDATE x_shop_discount SET used_count = used_count - 1 WHERE id = :id AND used_count > 0')->execute(['id' => $id]);
    }

    /** @return string|null a language key of the problem, null on success */
    public function create(string $code, string $type, string $valueInput, bool $active, ?string $expiresAt, ?string $maxUsesInput, ?string $minSubtotalInput, string $locale): ?string
    {
        $code = mb_strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,31}$/', $code)) {
            return 'shop.discount.error.code';
        }
        if ($this->findByCode($code) !== null) {
            return 'shop.discount.error.code_taken';
        }

        if ($type === 'percent') {
            $value = (int) $valueInput;
            if ($value < 1 || $value > 100) {
                return 'shop.discount.error.value';
            }
        } elseif ($type === 'flat') {
            $value = \Modulento\Core\Support\Money::parse($valueInput);
            if ($value === null || $value < 1) {
                return 'shop.discount.error.value';
            }
        } else {
            return 'shop.discount.error.value';
        }

        $maxUses = trim((string) $maxUsesInput) !== '' ? (int) $maxUsesInput : null;
        if ($maxUses !== null && $maxUses < 1) {
            return 'shop.discount.error.max_uses';
        }
        $minSubtotalTrimmed = trim((string) $minSubtotalInput);
        $minSubtotal = null;
        if ($minSubtotalTrimmed !== '') {
            $minSubtotal = \Modulento\Core\Support\Money::parse($minSubtotalTrimmed);
            if ($minSubtotal === null) {
                return 'shop.discount.error.min_subtotal_invalid';
            }
        }

        $this->db->prepare(
            'INSERT INTO x_shop_discount (code, type, value, active, expires_at, max_uses, min_subtotal, created_at)
             VALUES (:code, :type, :value, :active, :expires, :max_uses, :min_subtotal, :now)'
        )->execute([
            'code' => $code, 'type' => $type, 'value' => $value, 'active' => $active ? 1 : 0,
            'expires' => $expiresAt !== null && $expiresAt !== '' ? $expiresAt . ' 23:59:59' : null,
            'max_uses' => $maxUses, 'min_subtotal' => $minSubtotal, 'now' => gmdate('Y-m-d H:i:s'),
        ]);

        return null;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->prepare('UPDATE x_shop_discount SET active = :active WHERE id = :id')->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM x_shop_discount WHERE id = :id')->execute(['id' => $id]);
    }

    private static function typed(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'code' => $row['code'], 'type' => $row['type'], 'value' => (int) $row['value'],
            'active' => (bool) $row['active'], 'expires_at' => $row['expires_at'],
            'max_uses' => $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
            'used_count' => (int) $row['used_count'],
            'min_subtotal' => $row['min_subtotal'] !== null ? (int) $row['min_subtotal'] : null,
        ];
    }
}
