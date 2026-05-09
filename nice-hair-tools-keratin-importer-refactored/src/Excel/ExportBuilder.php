<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ExportBuilderTrait
{
    public function export_family(string $family): array
    {
        $this->bootstrap_import_environment();
        $family = in_array($family, ['tools', 'keratin', 'ready_to_install', 'exclusive_hair', 'custom_hair'], true) ? $family : 'tools';
        $rows = match ($family) {
            'keratin' => $this->build_keratin_export_rows(),
            'ready_to_install' => $this->build_ready_to_install_export_rows(),
            'exclusive_hair' => $this->build_exclusive_hair_export_rows(),
            'custom_hair' => $this->build_custom_hair_export_rows(),
            default => $this->build_tools_export_rows(),
        };

        $filename = sprintf(
            'nice-hair-%s-export-%s.xlsx',
            $family,
            gmdate('Ymd-His')
        );

        return [
            'filename' => $filename,
            'content' => NH_TKI_XLSX_Writer::write($rows),
        ];
    }
    private function build_tools_export_rows(): array
    {
        $rows = [[
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Ссылка на видео',
        ]];

        foreach ($this->get_export_product_ids('tools') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Simple) {
                continue;
            }

            $rows[] = [
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                $product->get_sku(),
                $this->format_export_price($product->get_regular_price()),
                $this->format_export_price($product->get_sale_price()),
                $this->get_product_video_url((int) $product->get_id()),
            ];
        }

        return $rows;
    }
    private function build_keratin_export_rows(): array
    {
        $rows = [[
            'Подкатегория',
            'Название товара',
            'Описание товара',
            'Артикул',
            'Вес упаковки',
            'Цена без скидки',
            'Цена со скидкой',
            'Ссылка на видео',
        ]];

        foreach ($this->get_export_product_ids('keratin') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Variable) {
                continue;
            }

            $variations = $this->get_keratin_export_variations($product);

            if ($variations === []) {
                continue;
            }

            $rows[] = [
                $this->get_keratin_child_category_name((int) $product->get_id()),
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                implode(', ', array_column($variations, 'sku')),
                implode(', ', array_column($variations, 'weight')),
                implode(', ', array_map([$this, 'format_export_price'], array_column($variations, 'regular_price'))),
                $this->format_keratin_sale_price_export($variations),
                $this->get_product_video_url((int) $product->get_id()),
            ];
        }

        return $rows;
    }
    private function build_ready_to_install_export_rows(): array
    {
        $rows = [[
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Тип наращивания',
            'Качество волос',
            'Цветовая группа',
            'Текстура',
            'Длина',
            'В наличии',
            'Hot',
            'Ссылка на видео',
            'Фото',
        ]];

        foreach ($this->get_export_product_ids('ready_to_install') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Simple) {
                continue;
            }

            $rows[] = [
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                $product->get_sku(),
                $this->format_export_price($product->get_regular_price()),
                $this->format_export_price($product->get_sale_price()),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_extension_type'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_hair_quality'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_color_group'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_texture'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_length'),
                $product->is_in_stock() ? 'да' : 'нет',
                $product->get_featured() ? 'да' : 'нет',
                $this->get_product_video_url((int) $product->get_id()),
                '',
            ];
        }

        return $rows;
    }
    private function build_exclusive_hair_export_rows(): array
    {
        $rows = [[
            'Название товара',
            'Описание товара',
            'Артикул',
            'Базовая цена лота',
            'Цена до скидки',
            'Вес, гр',
            'Текстура',
            'Цветовая группа',
            'Длина',
            'В наличии',
            'Hot',
            'Ссылка на видео',
        ]];

        foreach ($this->get_export_product_ids('exclusive_hair') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Simple) {
                continue;
            }

            $rows[] = [
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                $product->get_sku(),
                $this->format_export_number($this->get_product_numeric_meta_value((int) $product->get_id(), 'nh_base_lot_price')),
                $this->format_export_number($this->get_product_numeric_meta_value((int) $product->get_id(), 'nh_compare_at_lot_price')),
                $this->format_export_number($this->get_product_numeric_meta_value((int) $product->get_id(), 'nh_fixed_weight_grams')),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_texture'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_color_group'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_length'),
                $product->is_in_stock() ? 'да' : 'нет',
                $product->get_featured() ? 'да' : 'нет',
                $this->get_product_video_url((int) $product->get_id()),
            ];
        }

        return $rows;
    }
    private function build_custom_hair_export_rows(): array
    {
        $rows = [[
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Тип наращивания',
            'Доступные длины',
            'Доступные качества',
            'Доступные текстуры',
            'Мин. вес, гр',
            'Шаг веса, гр',
            'Вес по умолчанию, гр',
            'Цветовые опции',
            'В наличии',
            'Featured',
            'Ссылка на видео',
            'Фото',
            'Статус',
        ]];

        foreach ($this->get_export_product_ids('custom_hair') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Simple) {
                continue;
            }

            $rows[] = [
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                $product->get_sku(),
                $this->format_export_price($product->get_regular_price()),
                $this->format_export_price($product->get_sale_price()),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_extension_type'),
                $this->get_custom_hair_choice_export_value((int) $product->get_id(), 'nh_custom_hair_available_lengths', $this->get_custom_hair_length_choice_map(), false),
                $this->get_custom_hair_choice_export_value((int) $product->get_id(), 'nh_custom_hair_available_qualities', $this->get_custom_hair_quality_choice_map()),
                $this->get_custom_hair_choice_export_value((int) $product->get_id(), 'nh_custom_hair_available_textures', $this->get_custom_hair_texture_choice_map()),
                $this->format_export_number($this->get_custom_hair_numeric_meta_value((int) $product->get_id(), 'nh_custom_hair_min_weight_grams')),
                $this->format_export_number($this->get_custom_hair_numeric_meta_value((int) $product->get_id(), 'nh_custom_hair_weight_step_grams')),
                $this->format_export_number($this->get_custom_hair_numeric_meta_value((int) $product->get_id(), 'nh_custom_hair_default_weight_grams')),
                $this->format_custom_hair_color_options_export((int) $product->get_id()),
                $product->is_in_stock() ? 'да' : 'нет',
                $product->get_featured() ? 'да' : 'нет',
                $this->get_product_video_url((int) $product->get_id()),
            ];
        }

        return $rows;
    }
    private function get_export_product_ids(string $family): array
    {
        $category = match ($family) {
            'keratin' => $this->get_product_category_term('keratin', NH_TKI_Plugin::KERATIN_CATEGORY),
            'ready_to_install' => $this->get_product_category_term('ready-to-install', NH_TKI_Plugin::READY_CATEGORY),
            'exclusive_hair' => $this->get_product_category_term('exclusive-hair', NH_TKI_Plugin::EXCLUSIVE_CATEGORY),
            'custom_hair' => $this->get_product_category_term('custom-hair', NH_TKI_Plugin::CUSTOM_HAIR_CATEGORY),
            default => $this->get_product_category_term('tools', NH_TKI_Plugin::TOOLS_CATEGORY),
        };

        if (! $category instanceof WP_Term) {
            return [];
        }

        $taxQuery = [
            'relation' => 'AND',
            [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => [(int) $category->term_id],
                'include_children' => in_array($family, ['keratin', 'custom_hair'], true),
            ],
            [
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => [$family === 'keratin' ? 'variable' : 'simple'],
            ],
        ];

        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => $taxQuery,
            'orderby' => [
                'menu_order' => 'ASC',
                'title' => 'ASC',
                'ID' => 'ASC',
            ],
            'no_found_rows' => true,
        ]);

        return is_array($ids) ? array_map('intval', $ids) : [];
    }
    private function get_product_category_term(string $slug, string $name): ?WP_Term
    {
        $term = get_term_by('slug', $slug, 'product_cat');

        if ($term instanceof WP_Term) {
            return $term;
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'name' => $name,
            'number' => 1,
        ]);

        return is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term ? $terms[0] : null;
    }
    private function get_product_video_url(int $productId): string
    {
        if (function_exists('get_field')) {
            $value = get_field('nh_product_video_url', $productId);

            if (is_string($value)) {
                return $value;
            }
        }

        return (string) get_post_meta($productId, 'nh_product_video_url', true);
    }
    private function get_product_attribute_export_value(int $productId, string $taxonomy): string
    {
        if (! taxonomy_exists($taxonomy)) {
            return '';
        }

        $terms = wc_get_product_terms($productId, $taxonomy, ['fields' => 'names']);

        if (! is_array($terms) || $terms === []) {
            return '';
        }

        return implode(', ', array_map('strval', $terms));
    }
    private function get_custom_hair_choice_export_value(int $productId, string $fieldName, array $choiceMap, bool $normalizeKeys = true): string
    {
        $rawValue = function_exists('get_field')
            ? get_field($fieldName, $productId)
            : get_post_meta($productId, $fieldName, true);
        $values = is_array($rawValue)
            ? $rawValue
            : (is_string($rawValue) && trim($rawValue) !== '' ? [$rawValue] : []);

        if ($values === []) {
            return '';
        }

        $labels = [];

        foreach ($values as $value) {
            $key = $normalizeKeys
                ? $this->normalize_custom_hair_key((string) $value)
                : $this->normalize_custom_hair_numeric_key((string) $value);

            if ($key === '') {
                continue;
            }

            $labels[] = (string) ($choiceMap[$key] ?? $value);
        }

        return implode(', ', array_values(array_unique(array_filter($labels))));
    }
    private function format_custom_hair_color_options_export(int $productId): string
    {
        $rows = $this->get_custom_hair_color_option_rows($productId);
        $parts = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row['color_label'] ?? ''));
            $value = trim((string) ($row['color_value'] ?? ''));
            $group = trim((string) ($row['color_group'] ?? ''));
            $imageFile = $this->get_media_field_filename($row['main_image'] ?? null);

            if ($label === '' && $value === '') {
                continue;
            }

            $parts[] = implode('|', [
                $label !== '' ? $label : $value,
                $value,
                $group,
                $imageFile,
            ]);
        }

        return implode('; ', $parts);
    }
    private function get_custom_hair_color_option_rows(int $productId): array
    {
        if (function_exists('get_field')) {
            $rows = get_field('nh_custom_hair_color_options', $productId);

            if (is_array($rows)) {
                return array_values(array_filter($rows, 'is_array'));
            }
        }

        $count = (int) get_post_meta($productId, 'nh_custom_hair_color_options', true);
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $rows[] = [
                'color_label' => get_post_meta($productId, 'nh_custom_hair_color_options_' . $index . '_color_label', true),
                'color_value' => get_post_meta($productId, 'nh_custom_hair_color_options_' . $index . '_color_value', true),
                'color_group' => get_post_meta($productId, 'nh_custom_hair_color_options_' . $index . '_color_group', true),
                'main_image' => get_post_meta($productId, 'nh_custom_hair_color_options_' . $index . '_main_image', true),
            ];
        }

        return $rows;
    }
    private function get_custom_hair_numeric_meta_value(int $productId, string $fieldName): ?float
    {
        $value = function_exists('get_field')
            ? get_field($fieldName, $productId)
            : get_post_meta($productId, $fieldName, true);

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
    private function get_media_field_filename(mixed $value): string
    {
        $attachmentId = $this->get_attachment_id_from_media_field($value);

        if ($attachmentId <= 0) {
            return '';
        }

        $file = get_attached_file($attachmentId);

        return is_string($file) && $file !== '' ? basename($file) : '';
    }
    private function get_attachment_id_from_media_field(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        if (is_array($value)) {
            foreach (['ID', 'id'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return max(0, (int) $value[$key]);
                }
            }
        }

        return 0;
    }
    private function get_custom_hair_length_choice_map(): array
    {
        if (function_exists('nice_hair_get_custom_hair_length_choice_map')) {
            $map = nice_hair_get_custom_hair_length_choice_map();

            if (is_array($map) && $map !== []) {
                return $map;
            }
        }

        return [
            '40' => '40 cm',
            '50' => '50 cm',
            '60' => '60 cm',
            '70' => '70 cm',
            '80' => '80 cm',
            '90' => '90 cm',
        ];
    }
    private function get_custom_hair_quality_choice_map(): array
    {
        if (function_exists('nice_hair_get_custom_hair_quality_choice_map')) {
            $map = nice_hair_get_custom_hair_quality_choice_map();

            if (is_array($map) && $map !== []) {
                return $map;
            }
        }

        return [
            'lux' => 'Lux',
            'premium' => 'Premium',
            'exclusive' => 'Exclusive',
        ];
    }
    private function get_custom_hair_texture_choice_map(): array
    {
        if (function_exists('nice_hair_get_custom_hair_texture_choice_map')) {
            $map = nice_hair_get_custom_hair_texture_choice_map();

            if (is_array($map) && $map !== []) {
                return $map;
            }
        }

        return [
            'soft_straight' => 'Soft straight',
            'silky_wavy' => 'Silky wavy',
            'amazing_curly' => 'Amazing curly',
        ];
    }
    private function get_custom_hair_color_group_choice_map(): array
    {
        if (function_exists('nice_hair_get_custom_hair_color_group_choice_map')) {
            $map = nice_hair_get_custom_hair_color_group_choice_map();

            if (is_array($map) && $map !== []) {
                return $map;
            }
        }

        return [
            'light' => 'Light',
            'middle' => 'Middle',
            'dark' => 'Dark',
        ];
    }
    private function get_keratin_export_variations(WC_Product_Variable $product): array
    {
        $rows = [];

        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product((int) $variationId);

            if (! $variation instanceof WC_Product_Variation || $variation->get_status() !== 'publish') {
                continue;
            }

            $weight = $variation->get_attribute(NH_TKI_Plugin::WEIGHT_TAXONOMY);

            if ($weight === '') {
                $attributes = $variation->get_attributes();
                $weight = (string) ($attributes[NH_TKI_Plugin::WEIGHT_TAXONOMY] ?? $attributes[NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG] ?? '');
            }

            $rows[] = [
                'sku' => $variation->get_sku(),
                'weight' => $this->normalize_export_weight($weight),
                'regular_price' => $variation->get_regular_price(),
                'sale_price' => $variation->get_sale_price(),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return self::weight_sort_value((string) ($left['weight'] ?? '')) <=> self::weight_sort_value((string) ($right['weight'] ?? ''));
        });

        return $rows;
    }
    private function get_keratin_child_category_name(int $productId): string
    {
        $keratin = $this->get_product_category_term('keratin', NH_TKI_Plugin::KERATIN_CATEGORY);
        $terms = wp_get_object_terms($productId, 'product_cat');

        if (! is_array($terms)) {
            return '';
        }

        foreach ($terms as $term) {
            if ($term instanceof WP_Term && $keratin instanceof WP_Term && (int) $term->parent === (int) $keratin->term_id) {
                return $term->name;
            }
        }

        foreach ($terms as $term) {
            if ($term instanceof WP_Term && (! $keratin instanceof WP_Term || (int) $term->term_id !== (int) $keratin->term_id)) {
                return $term->name;
            }
        }

        return '';
    }
    private function derive_keratin_base_sku(WC_Product_Variable $product, array $variations): string
    {
        $parentSku = $product->get_sku();

        if ($parentSku !== '') {
            return $parentSku;
        }

        $candidates = [];

        foreach ($variations as $variation) {
            $sku = (string) ($variation['sku'] ?? '');

            if ($sku === '') {
                continue;
            }

            $candidates[] = $this->strip_weight_suffix_from_sku($sku, (string) ($variation['weight'] ?? ''));
        }

        $candidates = array_values(array_filter(array_unique($candidates), static fn(string $value): bool => $value !== ''));

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        return $candidates[0] ?? '';
    }
    private function strip_weight_suffix_from_sku(string $sku, string $weight): string
    {
        $weight = strtoupper(str_replace(' ', '', $weight));

        if ($weight === '') {
            return $sku;
        }

        $tokens = array_unique([
            $weight,
            $weight . 'G',
            str_replace('G', '', $weight),
        ]);

        foreach ($tokens as $token) {
            foreach (['-', '_', ' '] as $separator) {
                $suffix = $separator . $token;

                if ($token !== '' && strtoupper(substr($sku, -strlen($suffix))) === strtoupper($suffix)) {
                    return rtrim(substr($sku, 0, -strlen($suffix)), '-_ ');
                }
            }
        }

        return $sku;
    }
    private function normalize_export_weight(string $weight): string
    {
        $weight = trim($weight);
        $weight = preg_replace('/\s+/u', '', $weight) ?? $weight;

        return preg_replace('/g$/i', '', $weight) ?? $weight;
    }
    private function format_keratin_sale_price_export(array $variations): string
    {
        $hasSale = false;

        foreach ($variations as $variation) {
            if ((string) ($variation['sale_price'] ?? '') !== '') {
                $hasSale = true;
                break;
            }
        }

        if (! $hasSale) {
            return '';
        }

        return implode(', ', array_map(function (array $variation): string {
            return $this->format_export_price((string) ($variation['sale_price'] ?? ''));
        }, $variations));
    }
    private function format_export_price(mixed $price): string
    {
        $price = is_string($price) ? trim($price) : (string) $price;

        if ($price === '') {
            return '';
        }

        $number = (float) $price;
        $formatted = floor($number) === $number
            ? number_format($number, 0, '.', '')
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');

        return $formatted . '$';
    }
    private function format_export_number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! is_numeric($value)) {
            return '';
        }

        $number = (float) $value;

        return floor($number) === $number
            ? number_format($number, 0, '.', '')
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
    private function get_product_numeric_meta_value(int $productId, string $metaKey): ?float
    {
        $value = get_post_meta($productId, $metaKey, true);

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }
}
