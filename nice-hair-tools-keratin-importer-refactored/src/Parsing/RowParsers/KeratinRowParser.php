<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_KeratinRowParserTrait
{
    private function parse_keratin_rows(array $rows, array $headers, int $rowOffset = 0): array
    {
        $items = [];
        $warnings = [];
        $errors = [];
        $map = array_flip($headers);
        $hasLegacyLists = isset($map['вес упаковки']) && ! isset($map['вес, гр']);

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $rowNumber = $i + 1 + $rowOffset;
            $title = $this->get_row_value($row, $map, 'название товара');
            $sku = $this->get_row_value($row, $map, 'артикул');

            if ($title === '' && $sku === '') {
                continue;
            }

            if ($title === '') {
                $errors[] = sprintf('Keratin row %d: title is missing.', $rowNumber);
                continue;
            }

            if ($hasLegacyLists) {
                $expanded = $this->expand_legacy_keratin_row($row, $map, $rowNumber);
                $items = array_merge($items, $expanded['rows']);
                $warnings = array_merge($warnings, $expanded['warnings']);
                $errors = array_merge($errors, $expanded['errors']);
                continue;
            }

            $weight = $this->parse_weight_label($this->get_row_value($row, $map, 'вес, гр'));
            $regularPrice = $this->parse_price($this->get_row_value($row, $map, 'цена без скидки'));
            $salePrice = $this->parse_price($this->get_row_value($row, $map, 'цена со скидкой'));

            if ($sku === '' || $weight === '' || $regularPrice === null) {
                $errors[] = sprintf('Keratin row %d (%s): sku, weight or regular price is invalid.', $rowNumber, $title);
                continue;
            }

            $subcategory = $this->resolve_keratin_child_category(
                $this->get_row_value($row, $map, 'подкатегория'),
                $title
            );

            if ($subcategory === '') {
                $errors[] = sprintf('Keratin row %d (%s): unable to resolve child category.', $rowNumber, $title);
                continue;
            }

            $items[] = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'weight' => $weight,
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'video_url' => $this->sanitize_video_url($this->get_row_value($row, $map, 'ссылка на видео')),
                'subcategory' => $subcategory,
            ];
        }

        return [
            'rows' => $items,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
    private function expand_legacy_keratin_row(array $row, array $map, int $rowNumber): array
    {
        $rows = [];
        $warnings = [];
        $errors = [];

        $title = $this->get_row_value($row, $map, 'название товара');
        $baseSku = trim($this->get_row_value($row, $map, 'артикул'));
        $description = $this->get_row_value($row, $map, 'описание товара');
        $videoUrl = $this->sanitize_video_url($this->get_row_value($row, $map, 'ссылка на видео'));
        $subcategory = $this->resolve_keratin_child_category(
            $this->get_row_value($row, $map, 'подкатегория'),
            $title
        );

        $weights = $this->parse_weight_list($this->get_row_value($row, $map, 'вес упаковки'));
        $regularPrices = $this->parse_price_list($this->get_row_value($row, $map, 'цена без скидки'));
        $salePrices = $this->parse_price_list($this->get_row_value($row, $map, 'цена со скидкой'), true);

        if ($title === '' || $baseSku === '') {
            $errors[] = sprintf('Keratin legacy row %d: title or base SKU is missing.', $rowNumber);
            return ['rows' => $rows, 'warnings' => $warnings, 'errors' => $errors];
        }

        if ($subcategory === '') {
            $errors[] = sprintf('Keratin legacy row %d (%s): unable to resolve child category.', $rowNumber, $title);
            return ['rows' => $rows, 'warnings' => $warnings, 'errors' => $errors];
        }

        if ($weights === [] || $regularPrices === [] || count($weights) !== count($regularPrices)) {
            $errors[] = sprintf('Keratin legacy row %d (%s): weights and prices do not match.', $rowNumber, $title);
            return ['rows' => $rows, 'warnings' => $warnings, 'errors' => $errors];
        }

        $skuList = $this->parse_text_list($baseSku);

        if (count($skuList) > 1 && count($skuList) !== count($weights)) {
            $errors[] = sprintf('Keratin legacy row %d (%s): SKU list and weights do not match.', $rowNumber, $title);
            return ['rows' => $rows, 'warnings' => $warnings, 'errors' => $errors];
        }

        foreach ($weights as $index => $weight) {
            $variationSku = isset($skuList[$index]) && $skuList[$index] !== ''
                ? $skuList[$index]
                : sprintf('%s-%s', $baseSku, strtoupper(str_replace('g', 'G', $weight)));
            $rows[] = [
                'title' => trim($title),
                'description' => $description,
                'sku' => $variationSku,
                'weight' => $weight,
                'regular_price' => $regularPrices[$index],
                'sale_price' => $salePrices[$index] ?? null,
                'video_url' => $videoUrl,
                'subcategory' => $subcategory,
            ];
        }

        $warnings[] = sprintf('Keratin legacy row %d expanded into %d variations using generated SKUs from base SKU %s.', $rowNumber, count($rows), $baseSku);

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
    private function group_keratin_rows(array $rows, array &$warnings, array &$errors): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groupKey = $this->build_group_key('keratin', $row['subcategory'], $row['title']);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'title' => $row['title'],
                    'subcategory' => $row['subcategory'],
                    'description' => '',
                    'video_url' => '',
                    'variations' => [],
                ];
            }

            if ($groups[$groupKey]['description'] === '' && $row['description'] !== '') {
                $groups[$groupKey]['description'] = $row['description'];
            }

            if ($groups[$groupKey]['video_url'] === '' && $row['video_url'] !== '') {
                $groups[$groupKey]['video_url'] = $row['video_url'];
            }

            $groups[$groupKey]['variations'][] = [
                'sku' => $row['sku'],
                'weight' => $row['weight'],
                'regular_price' => $row['regular_price'],
                'sale_price' => $row['sale_price'],
            ];
        }

        foreach ($groups as $groupKey => &$group) {
            $seenSkus = [];
            $seenWeights = [];

            foreach ($group['variations'] as $variation) {
                if (isset($seenSkus[$variation['sku']])) {
                    $errors[] = sprintf('Keratin group %s: duplicate SKU %s.', $group['title'], $variation['sku']);
                }

                if (isset($seenWeights[$variation['weight']])) {
                    $warnings[] = sprintf('Keratin group %s: duplicate weight %s, keeping multiple rows by SKU.', $group['title'], $variation['weight']);
                }

                $seenSkus[$variation['sku']] = true;
                $seenWeights[$variation['weight']] = true;
            }

            usort($group['variations'], static function (array $left, array $right): int {
                return NH_TKI_Importer::weight_sort_value($left['weight']) <=> NH_TKI_Importer::weight_sort_value($right['weight']);
            });
        }
        unset($group);

        return array_values($groups);
    }
    private static function weight_sort_value(string $label): int
    {
        return (int) preg_replace('/\D+/', '', $label);
    }
    private function build_group_key(string $family, string $subcategory, string $title): string
    {
        return sanitize_title($family . '-' . $subcategory . '-' . $title);
    }
    private function parse_weight_list(string $value): array
    {
        $parts = preg_split('/\s*,\s*/', trim($value)) ?: [];
        $result = [];

        foreach ($parts as $part) {
            $weight = $this->parse_weight_label($part);

            if ($weight !== '') {
                $result[] = $weight;
            }
        }

        return $result;
    }
    private function parse_text_list(string $value): array
    {
        $parts = preg_split('/\s*,\s*/', trim($value)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn(string $item): bool => $item !== ''));
    }
    private function parse_weight_label(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $numeric = preg_replace('/[^0-9.]+/', '', $value) ?? '';

        if ($numeric === '' || ! is_numeric($numeric)) {
            return '';
        }

        $number = (float) $numeric;
        $formatted = floor($number) === $number
            ? number_format($number, 0, '.', '')
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');

        return $formatted . 'g';
    }
    private function parse_price_list(string $value, bool $preserveEmpty = false): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $value) ?: [];
        $result = [];

        foreach ($parts as $part) {
            $parsed = $this->parse_price($part);

            if ($parsed !== null) {
                $result[] = $parsed;
            } elseif ($preserveEmpty) {
                $result[] = null;
            }
        }

        return $result;
    }
    private function parse_price(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(['$', ' ', "\xc2\xa0"], '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.]+/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
    private function parse_positive_number(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace([' ', "\xc2\xa0"], '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.]+/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        if ($number <= 0) {
            return null;
        }

        return number_format($number, 2, '.', '');
    }
    private function parse_boolean_field(string $value, bool $default): ?bool
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return $default;
        }

        return match ($value) {
            'yes', 'y', 'true', '1', 'да', 'д' => true,
            'no', 'n', 'false', '0', 'нет', 'н' => false,
            default => null,
        };
    }
    private function resolve_keratin_child_category(string $subcategory, string $title): string
    {
        $subcategory = trim($subcategory);
        $normalized = mb_strtolower($subcategory);

        $map = [
            'italian gel keratin' => 'Italian Gel Keratin',
            'pigmented keratin' => 'Pigmented Keratin',
        ];

        if ($subcategory !== '') {
            $existingCategoryName = $this->find_existing_keratin_child_category_name($subcategory);

            if ($existingCategoryName !== '') {
                return $existingCategoryName;
            }
        }

        if ($normalized !== '' && isset($map[$normalized])) {
            return $map[$normalized];
        }

        $titleNormalized = mb_strtolower(trim($title));

        if (str_contains($titleNormalized, 'italian gel keratin')) {
            return 'Italian Gel Keratin';
        }

        if (str_contains($titleNormalized, 'pigmented keratin')) {
            return 'Pigmented Keratin';
        }

        return '';
    }

    private function find_existing_keratin_child_category_name(string $name): string
    {
        if (! function_exists('get_terms') || ! taxonomy_exists('product_cat')) {
            return '';
        }

        $parentId = $this->find_keratin_parent_category_id_for_parser();

        if ($parentId <= 0) {
            return '';
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'name' => $name,
            'parent' => $parentId,
            'number' => 1,
        ]);

        if (is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) {
            return trim((string) $terms[0]->name);
        }

        $slug = sanitize_title($name);

        if ($slug === '') {
            return '';
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'slug' => $slug,
            'parent' => $parentId,
            'number' => 1,
        ]);

        if (is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) {
            return trim((string) $terms[0]->name);
        }

        return '';
    }

    private function find_keratin_parent_category_id_for_parser(): int
    {
        if (! function_exists('get_terms') || ! taxonomy_exists('product_cat')) {
            return 0;
        }

        foreach (['name' => NH_TKI_Plugin::KERATIN_CATEGORY, 'slug' => sanitize_title(NH_TKI_Plugin::KERATIN_CATEGORY)] as $field => $value) {
            if ($value === '') {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                $field => $value,
                'parent' => 0,
                'number' => 1,
            ]);

            if (is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) {
                return (int) $terms[0]->term_id;
            }
        }

        return 0;
    }

    private function sanitize_video_url(string $url): string
    {
        $url = trim($url);

        return $url !== '' ? esc_url_raw($url) : '';
    }
}
