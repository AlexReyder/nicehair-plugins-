<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_CategoryServiceTrait
{
    private function ensure_product_category(string $name, string $parentName = ''): int
    {
        $parentId = 0;

        if ($parentName !== '') {
            $parentId = $this->ensure_product_category($parentName);
        }

        $slug = sanitize_title($name);
        $existing = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'name' => $name,
            'parent' => $parentId,
            'number' => 1,
        ]);

        if (is_array($existing) && isset($existing[0]) && $existing[0] instanceof WP_Term) {
            return (int) $existing[0]->term_id;
        }

        if ($slug !== '') {
            $existingBySlug = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'slug' => $slug,
                'parent' => $parentId,
                'number' => 1,
            ]);

            if (is_array($existingBySlug) && isset($existingBySlug[0]) && $existingBySlug[0] instanceof WP_Term) {
                return (int) $existingBySlug[0]->term_id;
            }
        }

        $termExists = term_exists($name, 'product_cat', $parentId);

        if (is_array($termExists) && isset($termExists['term_id'])) {
            return (int) $termExists['term_id'];
        }

        if (is_int($termExists) && $termExists > 0) {
            return $termExists;
        }

        $created = wp_insert_term($name, 'product_cat', [
            'parent' => $parentId,
        ]);

        if (is_wp_error($created)) {
            if ($created->get_error_code() === 'term_exists') {
                $termId = (int) $created->get_error_data();

                if ($termId > 0) {
                    return $termId;
                }
            }

            throw new RuntimeException('Failed to create category: ' . $name . ' (' . $created->get_error_message() . ')');
        }

        return (int) $created['term_id'];
    }
}
