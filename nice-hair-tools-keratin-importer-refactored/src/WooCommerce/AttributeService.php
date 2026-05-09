<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_AttributeServiceTrait
{
    private function build_product_taxonomy_attributes(array $valuesByTaxonomy): array
    {
        $attributes = [];

        foreach ($valuesByTaxonomy as $taxonomy => $value) {
            $taxonomy = sanitize_key((string) $taxonomy);
            $value = trim((string) $value);

            if ($taxonomy === '' || $value === '') {
                continue;
            }

            $term = $this->ensure_attribute_term($taxonomy, $value, $this->get_attribute_label_for_taxonomy($taxonomy));

            if (! $term instanceof WP_Term) {
                continue;
            }

            $attributeSlug = preg_replace('/^pa_/', '', $taxonomy) ?? $taxonomy;
            $attribute = new WC_Product_Attribute();
            $attribute->set_id((int) wc_attribute_taxonomy_id_by_name($attributeSlug));
            $attribute->set_name($taxonomy);
            $attribute->set_options([(int) $term->term_id]);
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes[] = $attribute;
        }

        return $attributes;
    }
    private function ensure_attribute_term(string $taxonomy, string $label, string $attributeLabel): ?WP_Term
    {
        if (! taxonomy_exists($taxonomy)) {
            $attributeSlug = preg_replace('/^pa_/', '', $taxonomy) ?? $taxonomy;
            $attributeId = wc_attribute_taxonomy_id_by_name($attributeSlug);

            if (! $attributeId) {
                $attributeId = wc_create_attribute([
                    'name' => $attributeLabel,
                    'slug' => $attributeSlug,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => true,
                ]);

                if (is_wp_error($attributeId)) {
                    return null;
                }

                delete_transient('wc_attribute_taxonomies');
            }

            register_taxonomy($taxonomy, 'product', [
                'label' => $attributeLabel,
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ]);
        }

        $slug = sanitize_title($label);
        $term = get_term_by('slug', $slug, $taxonomy);

        if (! $term instanceof WP_Term) {
            $termId = term_exists($label, $taxonomy);

            if (is_array($termId) && isset($termId['term_id'])) {
                $term = get_term((int) $termId['term_id'], $taxonomy);
            }
        }

        if ($term instanceof WP_Term) {
            return $term;
        }

        $created = wp_insert_term($label, $taxonomy, ['slug' => $slug]);

        if (is_wp_error($created)) {
            return null;
        }

        $term = get_term((int) $created['term_id'], $taxonomy);

        return $term instanceof WP_Term ? $term : null;
    }
    private function get_attribute_label_for_taxonomy(string $taxonomy): string
    {
        return match ($taxonomy) {
            'pa_extension_type' => 'Hair extensions type',
            'pa_hair_quality' => 'Hair quality',
            'pa_color_group' => 'Color',
            'pa_texture' => 'Texture',
            'pa_length' => 'Length',
            'pa_weight' => 'Weight',
            default => ucwords(str_replace(['pa_', '_', '-'], ['', ' ', ' '], $taxonomy)),
        };
    }
    private function ensure_weight_term(string $label): ?WP_Term
    {
        $taxonomy = NH_TKI_Plugin::WEIGHT_TAXONOMY;

        if (! taxonomy_exists($taxonomy)) {
            $attributeId = wc_attribute_taxonomy_id_by_name(NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG);

            if (! $attributeId) {
                $attributeId = wc_create_attribute([
                    'name' => 'Weight',
                    'slug' => NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false,
                ]);

                delete_transient('wc_attribute_taxonomies');
            }

            register_taxonomy($taxonomy, 'product', [
                'label' => 'Weight',
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ]);
        }

        $slug = sanitize_title($label);
        $term = get_term_by('slug', $slug, $taxonomy);

        if ($term instanceof WP_Term) {
            return $term;
        }

        $created = wp_insert_term($label, $taxonomy, ['slug' => $slug]);

        if (is_wp_error($created)) {
            return null;
        }

        $term = get_term((int) $created['term_id'], $taxonomy);

        return $term instanceof WP_Term ? $term : null;
    }
}
