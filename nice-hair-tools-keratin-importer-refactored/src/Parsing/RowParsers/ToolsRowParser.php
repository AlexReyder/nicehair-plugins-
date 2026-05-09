<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ToolsRowParserTrait
{
    private function parse_tools_rows(array $rows, array $headers, int $rowOffset = 0): array
    {
        $items = [];
        $warnings = [];
        $errors = [];

        $map = array_flip($headers);

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $rowNumber = $i + 1 + $rowOffset;
            $title = $this->get_row_value($row, $map, 'название товара');
            $sku = $this->get_row_value($row, $map, 'артикул');

            if ($title === '' && $sku === '') {
                continue;
            }

            if ($title === '' || $sku === '') {
                $errors[] = sprintf('Tools row %d: title or SKU is missing.', $rowNumber);
                continue;
            }

            $regularPrice = $this->parse_price($this->get_row_value($row, $map, 'цена без скидки'));
            $salePrice = $this->parse_price($this->get_row_value($row, $map, 'цена со скидкой'));

            if ($regularPrice === null) {
                $errors[] = sprintf('Tools row %d (%s): regular price is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            $items[] = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'video_url' => $this->sanitize_video_url($this->get_row_value($row, $map, 'ссылка на видео')),
            ];
        }

        return [
            'items' => $items,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
}
