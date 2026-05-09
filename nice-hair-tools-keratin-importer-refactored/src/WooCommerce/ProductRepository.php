<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ProductRepositoryTrait
{
    private function find_product_by_sku(string $sku): ?WC_Product
    {
        if ($sku === '') {
            return null;
        }

        $productId = function_exists('wc_get_product_id_by_sku')
            ? (int) wc_get_product_id_by_sku($sku)
            : 0;

        return $productId > 0 ? wc_get_product($productId) : null;
    }
    private function find_product_by_group_key(string $groupKey): int
    {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => NH_TKI_Plugin::META_GROUP_KEY,
            'meta_value' => $groupKey,
            'no_found_rows' => true,
        ]);

        return isset($ids[0]) ? (int) $ids[0] : 0;
    }
}
