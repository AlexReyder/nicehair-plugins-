<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ReadyToInstallRowParserTrait
{
    private function parse_ready_to_install_rows(array $rows, array $headers, int $rowOffset = 0): array
    {
        $items = [];
        $warnings = [];
        $errors = [];
        $map = array_flip($headers);
        $requiredAttributes = [
            'тип наращивания' => 'extension_type',
            'качество волос' => 'hair_quality',
            'цветовая группа' => 'color_group',
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
                $errors[] = sprintf('Ready to Install row %d: title or SKU is missing.', $rowNumber);
                continue;
            }

            $regularPrice = $this->parse_price($this->get_row_value($row, $map, 'цена без скидки'));
            $salePrice = $this->parse_price($this->get_row_value($row, $map, 'цена со скидкой'));

            if ($regularPrice === null) {
                $errors[] = sprintf('Ready to Install row %d (%s): regular price is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            $attributes = [
                'pa_extension_type' => $this->get_row_value($row, $map, 'тип наращивания'),
                'pa_hair_quality' => $this->get_row_value($row, $map, 'качество волос'),
                'pa_color_group' => $this->get_row_value($row, $map, 'цветовая группа'),
                'pa_texture' => $this->get_row_value($row, $map, 'текстура'),
                'pa_length' => $this->get_row_value($row, $map, 'длина'),
            ];

            foreach ($requiredAttributes as $header => $key) {
                if ($this->get_row_value($row, $map, $header) === '') {
                    $errors[] = sprintf('Ready to Install row %d (%s): required attribute %s is missing.', $rowNumber, $sku, $key);
                    continue 2;
                }
            }

            $stockValue = $this->parse_boolean_field($this->get_row_value($row, $map, 'в наличии'), true);
            $featuredValue = $this->parse_boolean_field($this->get_first_row_value($row, $map, ['hot', 'featured']), false);
            $status = 'publish';

            if ($stockValue === null) {
                $errors[] = sprintf('Ready to Install row %d (%s): stock value must be yes/no or да/нет.', $rowNumber, $sku);
                continue;
            }

            if ($featuredValue === null) {
                $errors[] = sprintf('Ready to Install row %d (%s): Hot value must be yes/no or да/нет.', $rowNumber, $sku);
                continue;
            }

            $items[] = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'attributes' => array_filter($attributes, static fn(string $value): bool => trim($value) !== ''),
                'in_stock' => $stockValue,
                'featured' => $featuredValue,
                'video_url' => $this->sanitize_video_url($this->get_row_value($row, $map, 'ссылка на видео')),
                'photo_files' => $this->get_row_value($row, $map, 'фото'),
                'status' => $status,
            ];
        }

        return [
            'items' => $items,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
}
