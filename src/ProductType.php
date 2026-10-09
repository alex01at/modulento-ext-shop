<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferType;
use Modulento\Core\Support\Money;

final class ProductType implements OfferType
{
    private const MIN_PRICE = 1;
    private const MAX_PRICE = 100_000_000;
    private const MAX_STOCK = 1_000_000;

    public function id(): string
    {
        return 'shop.product';
    }

    public function labelKey(): string
    {
        return 'shop.type.product';
    }

    public function priceLabelKey(): string
    {
        return 'core.offer.price_from';
    }

    public function formTemplate(): string
    {
        return '@shop/offer_form.twig';
    }

    public function detailTemplate(): string
    {
        return '@shop/offer_detail.twig';
    }

    public function formData(?int $offerId, ?array $typed, App $app, ?array $locales = null): array
    {
        $locale = $app->translator->locale();
        $locales ??= $app->locales->enabled();

        if ($typed !== null) {
            // Shown again after a failed validation: exactly what was typed.
            $variants = [];
            for ($i = 0; $i < Variants::MAX; $i++) {
                $variants[$i] = is_array($typed['variant'][$i] ?? null) ? $typed['variant'][$i] : [];
            }

            return ['variants' => $variants, 'locales' => $locales];
        }

        $stored = $offerId !== null ? (new Variants($app->db))->forOffer($offerId) : [];
        $variants = [];
        foreach ($stored as $index => $variant) {
            $variants[$index] = [
                'id' => $variant['id'],
                'sku' => $variant['sku'],
                'price' => Money::input($variant['price'], $locale),
                'stock' => $variant['stock'],
                'digital' => $variant['is_digital'],
                'file_name' => $variant['file_original_name'],
                'file_bytes' => $variant['file_bytes'],
                'text' => $variant['texts'],
            ];
        }

        return ['variants' => $variants, 'locales' => $locales];
    }

    public function validate(array $input, ?int $offerId, App $app): array
    {
        $errors = [];
        $locales = $app->locales->enabled();
        $variants = [];
        $skusSeen = [];

        for ($i = 0; $i < Variants::MAX; $i++) {
            $raw = is_array($input['variant'][$i] ?? null) ? $input['variant'][$i] : [];
            $priceInput = trim((string) ($raw['price'] ?? ''));

            // The first variant is the product; the rest are optional and
            // exist once they have a price.
            if ($priceInput === '' && $i !== 0) {
                continue;
            }

            $price = Money::parse($priceInput);
            $stock = (int) ($raw['stock'] ?? -1);
            $sku = trim((string) ($raw['sku'] ?? ''));
            $sku = $sku !== '' ? mb_strtoupper(mb_substr($sku, 0, 64)) : null;

            if ($price === null || $price < self::MIN_PRICE || $price > self::MAX_PRICE) {
                $errors[] = 'shop.error.price';
            }
            if ($stock < 0 || $stock > self::MAX_STOCK) {
                $errors[] = 'shop.error.stock';
            }
            if ($sku !== null) {
                if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/', $sku) !== 1) {
                    $errors[] = 'shop.error.sku';
                } elseif (isset($skusSeen[$sku]) || $this->skuTaken($app, $sku, $offerId)) {
                    $errors[] = 'shop.error.sku_taken';
                }
                $skusSeen[$sku] = true;
            }

            $texts = [];
            foreach ($locales as $locale) {
                $label = trim((string) ($raw['text'][$locale]['label'] ?? ''));
                if ($label !== '') {
                    $texts[$locale] = mb_substr($label, 0, 150);
                }
            }
            if ($texts === []) {
                $errors[] = 'shop.error.label';
            }

            $id = isset($raw['id']) && ctype_digit((string) $raw['id']) ? (int) $raw['id'] : null;
            $variants[] = ['id' => $id, 'sku' => $sku, 'price' => (int) $price, 'stock' => $stock < 0 ? 0 : $stock, 'is_digital' => isset($raw['digital']), 'texts' => $texts];
        }

        if ($variants === []) {
            $errors[] = 'shop.error.price';
        }

        return ['values' => ['variants' => $variants], 'errors' => array_values(array_unique($errors))];
    }

    public function save(int $offerId, array $values, App $app): ?int
    {
        return (new Variants($app->db))->save($offerId, $values['variants'], new Downloads($app->db, self::uploadDir($app)));
    }

    public function detailData(int $offerId, string $locale, App $app): array
    {
        $default = $app->locales->default();
        $variantsService = new Variants($app->db);
        $stored = $variantsService->forOffer($offerId);

        return [
            'currency' => $app->offers->find($offerId)['currency'] ?? $app->offers->currency(),
            'variants' => array_map(fn (array $variant) => [
                'id' => $variant['id'],
                'label' => $variantsService->label($variant, $locale, $default),
                'price' => $variant['price'],
                'is_digital' => $variant['is_digital'],
                // Stock running out means nothing for a digital variant.
                'in_stock' => $variant['is_digital'] || $variant['stock'] > 0,
                'stock' => $variant['stock'],
            ], $stored),
        ];
    }

    public static function uploadDir(App $app): string
    {
        $config = $app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/shop-downloads';
    }

    // core/src/Catalogue/Offers.php and others note: MariaDB's native prepares
    // (PDO::ATTR_EMULATE_PREPARES is off) reject a named placeholder used
    // twice in one query, so ":offer" and ":offer2" below are deliberate,
    // not a typo.
    private function skuTaken(App $app, string $sku, ?int $offerId): bool
    {
        $stmt = $app->db->prepare(
            'SELECT 1 FROM x_shop_variant WHERE sku = :sku AND (offer_id <> :offer OR :offer2 IS NULL) LIMIT 1'
        );
        $stmt->execute(['sku' => $sku, 'offer' => $offerId, 'offer2' => $offerId]);

        return $stmt->fetchColumn() !== false;
    }
}
