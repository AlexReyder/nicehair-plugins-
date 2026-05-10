<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ExclusiveHairProductImporterTrait
{
    private function import_exclusive_hair_product(array $item, array $imageEntries, array &$report): array
    {
        $existing = $this->find_product_by_sku((string) $item['sku']);

        if ($existing instanceof WC_Product && ! $existing->is_type('simple')) {
            $report['errors'][] = sprintf('Exclusive Hair SKU %s is already used by a non-simple product.', (string) $item['sku']);

            return ['action' => 'error'];
        }

        $product = $existing instanceof WC_Product_Simple
            ? $existing
            : new WC_Product_Simple($existing instanceof WC_Product ? $existing->get_id() : 0);
        $inStock = (bool) ($item['in_stock'] ?? true);

        $product->set_name((string) $item['title']);
        $product->set_sku((string) $item['sku']);
        $product->set_status((string) ($item['status'] ?? 'publish'));
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(true);
        $product->set_sold_individually(true);
        $product->set_stock_quantity($inStock ? 1 : 0);
        $product->set_stock_status($inStock ? 'instock' : 'outofstock');
        $product->set_featured((bool) ($item['featured'] ?? false));
        $product->set_category_ids([$this->ensure_product_category(NH_TKI_Plugin::EXCLUSIVE_CATEGORY)]);
        $regularPrice = (string) ($item['regular_price'] ?? $item['base_lot_price'] ?? '');
        $salePrice = $item['sale_price'] ?? null;
        $product->set_regular_price($regularPrice);
        $product->set_sale_price($salePrice !== null && $salePrice !== '' ? (string) $salePrice : '');

        $product->set_short_description((string) ($item['description'] ?? ''));
        $product->set_description((string) ($item['description'] ?? ''));
        $product->set_attributes($this->build_product_taxonomy_attributes((array) ($item['attributes'] ?? [])));
        $productId = $product->save();

        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'exclusive_hair');
        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_SKU, (string) $item['sku']);
        $this->update_video_meta($productId, (string) ($item['video_url'] ?? ''));
        $this->update_unique_item_meta($productId, true);
        $this->update_exclusive_pricing_meta(
            $productId,
            (string) ($item['base_lot_price'] ?? ''),
            (string) ($item['fixed_weight_grams'] ?? ''),
            $item['compare_at_lot_price'] ?? null
        );

        if ($imageEntries !== []) {
            $imageIds = $this->import_images_for_key($imageEntries, 'exclusive_hair|' . (string) $item['sku'], $productId, (string) $item['sku'], $report);

            if ($imageIds !== []) {
                $product->set_image_id((int) $imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
                $product->save();
            }
        } else {
            $report['missing_images'][] = [
                'family' => 'exclusive_hair',
                'title' => (string) $item['title'],
                'key' => (string) $item['sku'],
            ];
        }

        wc_delete_product_transients($productId);

        return [
            'action' => $existing instanceof WC_Product ? 'updated' : 'created',
        ];
    }
}
