<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ToolsProductImporterTrait
{
    private function import_tool_product(array $item, array $imageEntries, array &$report): array
    {
        $existing = $this->find_product_by_sku($item['sku']);

        if ($existing instanceof WC_Product && ! $existing->is_type('simple')) {
            $report['errors'][] = sprintf('Tools SKU %s is already used by a non-simple product.', $item['sku']);

            return ['action' => 'error'];
        }

        $product = $existing instanceof WC_Product_Simple
            ? $existing
            : new WC_Product_Simple($existing instanceof WC_Product ? $existing->get_id() : 0);

        $product->set_name($item['title']);
        $product->set_sku($item['sku']);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_category_ids([$this->ensure_product_category(NH_TKI_Plugin::TOOLS_CATEGORY)]);
        $product->set_regular_price($item['regular_price']);
        $product->set_sale_price($item['sale_price'] ?? '');
        $product->set_short_description($item['description']);
        $product->set_description($item['description']);
        $productId = $product->save();

        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'tools');
        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_SKU, $item['sku']);

        $this->update_video_meta($productId, $item['video_url']);

        if ($imageEntries !== []) {
            $imageIds = $this->import_images_for_key($imageEntries, 'tools|' . $item['sku'], $productId, $item['sku'], $report);

            if ($imageIds !== []) {
                $product->set_image_id((int) $imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
                $product->save();
            }
        } else {
            $report['missing_images'][] = [
                'family' => 'tools',
                'title' => $item['title'],
                'key' => $item['sku'],
            ];
        }

        return [
            'action' => $existing instanceof WC_Product ? 'updated' : 'created',
        ];
    }
}
