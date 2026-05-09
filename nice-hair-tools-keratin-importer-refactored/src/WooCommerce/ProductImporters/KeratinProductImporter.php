<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_KeratinProductImporterTrait
{
    private function import_keratin_group(array $group, array $imageEntries, array &$report): array
    {
        $parentId = $this->find_product_by_group_key($group['group_key']);
        $isUpdate = $parentId > 0;

        if ($isUpdate) {
            wp_set_object_terms($parentId, 'variable', 'product_type');
            $product = new WC_Product_Variable($parentId);
        } else {
            $product = new WC_Product_Variable();
        }

        $keratinParentId = $this->ensure_product_category(NH_TKI_Plugin::KERATIN_CATEGORY);
        $childCategoryId = $this->ensure_product_category($group['subcategory'], NH_TKI_Plugin::KERATIN_CATEGORY);

        $product->set_name($group['title']);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_category_ids([$keratinParentId, $childCategoryId]);
        $product->set_short_description($group['description']);
        $product->set_description($group['description']);

        $attributeOptions = [];

        foreach ($group['variations'] as $variation) {
            $term = $this->ensure_weight_term($variation['weight']);

            if ($term instanceof WP_Term) {
                $attributeOptions[] = (int) $term->term_id;
            }
        }

        $attributeOptions = array_values(array_unique($attributeOptions));

        $attribute = new WC_Product_Attribute();
        $attribute->set_id((int) wc_attribute_taxonomy_id_by_name(NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG));
        $attribute->set_name(NH_TKI_Plugin::WEIGHT_TAXONOMY);
        $attribute->set_options($attributeOptions);
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $product->set_attributes([$attribute]);

        if (! empty($group['variations'][0]['weight'])) {
            $product->set_default_attributes([
                NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG => $group['variations'][0]['weight'],
            ]);
        }

        $parentId = $product->save();

        update_post_meta($parentId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'keratin');
        update_post_meta($parentId, NH_TKI_Plugin::META_GROUP_KEY, $group['group_key']);

        $this->update_video_meta($parentId, $group['video_url']);

        foreach ($group['variations'] as $variation) {
            $existingVariationId = wc_get_product_id_by_sku($variation['sku']);

            if ($existingVariationId > 0) {
                $existingVariation = wc_get_product($existingVariationId);

                if (! $existingVariation instanceof WC_Product_Variation) {
                    $report['errors'][] = sprintf('Keratin variation SKU %s is already used by a non-variation product.', $variation['sku']);
                    continue;
                }

                $variationProduct = $existingVariation;
            } else {
                $variationProduct = new WC_Product_Variation();
            }

            $variationProduct->set_parent_id($parentId);
            $variationProduct->set_sku($variation['sku']);
            $variationProduct->set_status('publish');
            $variationProduct->set_manage_stock(false);
            $variationProduct->set_stock_status('instock');
            $variationProduct->set_regular_price($variation['regular_price']);
            $variationProduct->set_sale_price($variation['sale_price'] ?? '');
            $variationProduct->set_attributes([
                NH_TKI_Plugin::WEIGHT_TAXONOMY => $variation['weight'],
            ]);
            $variationId = $variationProduct->save();

            update_post_meta($variationId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'keratin');
            update_post_meta($variationId, NH_TKI_Plugin::META_GROUP_KEY, $group['group_key']);
            update_post_meta($variationId, NH_TKI_Plugin::META_SOURCE_SKU, $variation['sku']);
        }

        WC_Product_Variable::sync($parentId);
        wc_delete_product_transients($parentId);

        if ($imageEntries !== []) {
            $imageIds = $this->import_images_for_key($imageEntries, 'keratin|' . $group['group_key'], $parentId, $group['group_key'], $report);

            if ($imageIds !== []) {
                $product = new WC_Product_Variable($parentId);
                $product->set_image_id((int) $imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
                $product->save();
            }
        } else {
            $report['missing_images'][] = [
                'family' => 'keratin',
                'title' => $group['title'],
                'key' => $group['group_key'],
            ];
        }

        return [
            'action' => $isUpdate ? 'updated' : 'created',
        ];
    }
}
