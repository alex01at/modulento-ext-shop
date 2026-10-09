<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\App;
use Modulento\Core\Event\OfferStatusChanged;
use Modulento\Core\Support\Money;

/**
 * Bulk-creates products from a CSV file: one row per variant, several rows
 * with the same "product" column become one product with several
 * variants. Creates only - a product whose title already exists for this
 * provider is skipped rather than merged or updated, so a second import
 * can never silently change or duplicate something that is already there.
 * One language per import (whichever the uploading account is using); a
 * shop that needs more just imports once per language, choosing the
 * locale by switching it beforehand.
 */
final class CsvImport
{
    public const TEMPLATE_HEADER = ['product', 'category', 'summary', 'description', 'variant_label', 'sku', 'price', 'stock', 'digital'];
    private const MAX_ROWS = 1000;

    /** @return array{created: int, variants: int, skipped: list<string>, errors: list<array{product: string, row: ?int, key: string}>, fatal: ?string} */
    public function import(string $path, int $providerId, string $locale, App $app): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['created' => 0, 'variants' => 0, 'skipped' => [], 'errors' => [], 'fatal' => 'shop.import.error.file'];
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);

            return ['created' => 0, 'variants' => 0, 'skipped' => [], 'errors' => [], 'fatal' => 'shop.import.error.empty'];
        }
        $header = array_map(fn (string $name) => mb_strtolower(trim($name)), $header);

        $groups = [];
        $order = [];
        $rowNumber = 1;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false && $rowNumber <= self::MAX_ROWS) {
            $rowNumber++;
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $fields = [];
            foreach ($header as $index => $name) {
                $fields[$name] = trim((string) ($row[$index] ?? ''));
            }
            $title = $fields['product'] ?? '';
            if ($title === '') {
                continue;
            }
            if (!isset($groups[$title])) {
                $groups[$title] = [];
                $order[] = $title;
            }
            $groups[$title][] = ['row' => $rowNumber, 'fields' => $fields];
        }
        fclose($handle);

        $existingTitles = $this->existingTitles($providerId, $app);
        $existingSkus = $this->existingSkus($app);
        $categories = $this->categoriesByName($locale, $app);
        $approvalRequired = $app->offers->approvalRequired();

        $created = 0;
        $variantCount = 0;
        $skipped = [];
        $errors = [];

        foreach ($order as $title) {
            if (isset($existingTitles[mb_strtolower($title)])) {
                $skipped[] = $title;
                continue;
            }

            $rows = $groups[$title];
            $first = $rows[0]['fields'];
            $variants = [];
            foreach ($rows as $entry) {
                $fields = $entry['fields'];
                $label = trim((string) ($fields['variant_label'] ?? ''));
                $price = Money::parse((string) ($fields['price'] ?? ''));
                if ($label === '' || $price === null || $price < 1) {
                    $errors[] = ['product' => $title, 'row' => $entry['row'], 'key' => 'shop.import.error.row'];
                    continue;
                }
                $sku = trim((string) ($fields['sku'] ?? ''));
                $sku = $sku !== '' ? mb_strtoupper(mb_substr($sku, 0, 64)) : null;
                if ($sku !== null) {
                    if (isset($existingSkus[$sku])) {
                        $errors[] = ['product' => $title, 'row' => $entry['row'], 'key' => 'shop.import.error.sku_taken'];
                        continue;
                    }
                    $existingSkus[$sku] = true;
                }
                $digital = in_array(mb_strtolower((string) ($fields['digital'] ?? '')), ['1', 'ja', 'yes', 'true'], true);
                $variants[] = [
                    'id' => null, 'sku' => $sku, 'price' => $price,
                    'stock' => max(0, (int) ($fields['stock'] ?? 0)), 'is_digital' => $digital,
                    'texts' => [$locale => mb_substr($label, 0, 150)],
                ];
            }
            if ($variants === []) {
                $errors[] = ['product' => $title, 'row' => null, 'key' => 'shop.import.error.no_variant'];
                continue;
            }

            $categoryName = mb_strtolower(trim((string) ($first['category'] ?? '')));
            $categoryId = $categoryName !== '' ? ($categories[$categoryName] ?? null) : null;

            $offerId = $app->offers->save(null, $providerId, 'shop.product', $categoryId, [
                $locale => [
                    'title' => mb_substr($title, 0, 150),
                    'summary' => mb_substr((string) ($first['summary'] ?? ''), 0, 300),
                    'description' => mb_substr((string) ($first['description'] ?? ''), 0, 10000),
                ],
            ]);
            $price = (new Variants($app->db))->save($offerId, $variants, new Downloads($app->db, ProductType::uploadDir($app)));
            $app->offers->setPriceFrom($offerId, $price);
            $newStatus = $approvalRequired ? 'pending' : 'published';
            $app->offers->setStatus($offerId, $newStatus, null, null);
            $app->events->dispatch(new OfferStatusChanged($offerId, $providerId, 'draft', $newStatus));

            $created++;
            $variantCount += count($variants);
        }

        return ['created' => $created, 'variants' => $variantCount, 'skipped' => $skipped, 'errors' => $errors, 'fatal' => null];
    }

    /** @return array<string, true> existing titles of this provider, lower-cased, across every language */
    private function existingTitles(int $providerId, App $app): array
    {
        $stmt = $app->db->prepare(
            "SELECT t.title FROM offer_translation t JOIN offer o ON o.id = t.offer_id WHERE o.provider_id = :provider AND o.type = 'shop.product'"
        );
        $stmt->execute(['provider' => $providerId]);

        $titles = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $title) {
            $titles[mb_strtolower($title)] = true;
        }

        return $titles;
    }

    /** @return array<string, true> SKUs already in use, anywhere - they are unique across the whole shop */
    private function existingSkus(App $app): array
    {
        $skus = [];
        foreach ($app->db->query('SELECT sku FROM x_shop_variant WHERE sku IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN) as $sku) {
            $skus[$sku] = true;
        }

        return $skus;
    }

    /** @return array<string, int> category id by its lower-cased name in this language */
    private function categoriesByName(string $locale, App $app): array
    {
        $byName = [];
        foreach ($app->categories->all() as $category) {
            $name = $category['translations'][$locale]['name'] ?? null;
            if ($name !== null) {
                $byName[mb_strtolower($name)] = $category['id'];
            }
        }

        return $byName;
    }
}
