<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ExclusiveHairRowParserTrait
{
    private function parse_exclusive_hair_rows(array $rows, array $headers, int $rowOffset = 0): array
    {
        $items = [];
        $warnings = [];
        $errors = [];
        $map = array_flip($headers);
        $requiredAttributes = [
            'текстура' => 'texture',
            'цветовая группа' => 'color_group',
            'длина' => 'length',
        ];

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $rowNumber = $i + 1 + $rowOffset;
            $title = $this->get_row_value($row, $map, 'название товара');
            $sku = $this->get_row_value($row, $map, 'артикул');

            if ($title === '' && $sku === '') {
                continue;
            }

            if ($title === '' || $sku === '') {
                $errors[] = sprintf('Exclusive Hair row %d: title or SKU is missing.', $rowNumber);
                continue;
            }

            $baseLotRaw = $this->get_first_row_value($row, $map, ['базовая цена лота', 'base lot price']);
            $baseLotPrice = $this->parse_positive_number($baseLotRaw);

            if ($baseLotPrice === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): base lot price is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            // Variant B model for Exclusive Hair:
            // - WooCommerce regular price always equals the current base lot price.
            // - WooCommerce sale price is not used.
            // - Optional compare-at / old price is stored separately for frontend strike-through rendering.
            $regularPrice = $baseLotPrice;
            $compareAtLotRaw = $this->get_first_row_value($row, $map, [
                'цена до скидки',
                'compare-at price',
                'compare at price',
                'old price',
                'цена без скидки',
                'woocommerce базовая цена',
            ]);
            $compareAtLotPrice = $this->parse_positive_number($compareAtLotRaw);

            if ($compareAtLotPrice !== null && (float) $compareAtLotPrice <= (float) $baseLotPrice) {
                $warnings[] = sprintf(
                    'Exclusive Hair row %d (%s): compare-at lot price should be greater than base lot price; value was ignored.',
                    $rowNumber,
                    $sku
                );
                $compareAtLotPrice = null;
            }

            $fixedWeightRaw = $this->get_first_row_value($row, $map, ['вес, гр', 'вес гр', 'fixed weight']);
            $fixedWeight = $this->parse_positive_number($fixedWeightRaw);

            if ($fixedWeight === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): fixed weight is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            $attributes = [
                'pa_texture' => $this->get_row_value($row, $map, 'текстура'),
                'pa_color_group' => $this->get_row_value($row, $map, 'цветовая группа'),
                'pa_length' => $this->get_row_value($row, $map, 'длина'),
                'pa_weight' => $this->parse_weight_label($fixedWeight),
            ];

            foreach ($requiredAttributes as $header => $key) {
                if ($this->get_row_value($row, $map, $header) === '') {
                    $errors[] = sprintf('Exclusive Hair row %d (%s): required attribute %s is missing.', $rowNumber, $sku, $key);
                    continue 2;
                }
            }

            $stockValue = $this->parse_boolean_field($this->get_row_value($row, $map, 'в наличии'), true);
            $featuredValue = $this->parse_boolean_field($this->get_first_row_value($row, $map, ['hot', 'featured']), false);
            $status = 'publish';

            if ($stockValue === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): stock value must be yes or no.', $rowNumber, $sku);
                continue;
            }

            if ($featuredValue === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): Featured value must be yes or no.', $rowNumber, $sku);
                continue;
            }

            $item = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'regular_price' => $regularPrice,
                'base_lot_price' => $baseLotPrice,
                'compare_at_lot_price' => $compareAtLotPrice,
                'fixed_weight_grams' => $fixedWeight,
                'attributes' => array_filter($attributes, static fn(string $value): bool => trim($value) !== ''),
                'in_stock' => $stockValue,
                'featured' => $featuredValue,
                'video_url' => $this->sanitize_video_url($this->get_row_value($row, $map, 'ссылка на видео')),
                'photo_files' => $this->get_row_value($row, $map, 'фото'),
                'status' => $status,
            ];

            $items[] = $item;
        }

        return [
            'items' => $items,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
}
