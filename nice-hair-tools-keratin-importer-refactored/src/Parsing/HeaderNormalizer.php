<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_HeaderNormalizerTrait
{
    private function find_sheet_header(array $rows): ?array
    {
        $limit = min(10, count($rows));

        for ($index = 0; $index < $limit; $index++) {
            if (! isset($rows[$index]) || ! is_array($rows[$index])) {
                continue;
            }

            $headers = array_map([$this, 'normalize_header'], $rows[$index]);

            if ($this->is_ready_to_install_sheet($headers) || $this->is_exclusive_hair_sheet($headers) || $this->is_custom_hair_sheet($headers) || $this->is_tools_sheet($headers) || $this->is_keratin_sheet($headers)) {
                return [
                    'index' => $index,
                    'headers' => $headers,
                ];
            }
        }

        return null;
    }
    private function is_ready_to_install_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && in_array('тип наращивания', $headers, true)
            && in_array('качество волос', $headers, true)
            && in_array('цветовая группа', $headers, true);
    }
    private function is_exclusive_hair_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && in_array('текстура', $headers, true)
            && in_array('цветовая группа', $headers, true)
            && in_array('длина', $headers, true)
            && (in_array('вес, гр', $headers, true) || in_array('вес гр', $headers, true) || in_array('fixed weight', $headers, true));
    }
    private function is_custom_hair_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && in_array('цветовые опции', $headers, true)
            && (in_array('мин. вес, гр', $headers, true) || in_array('мин вес, гр', $headers, true) || in_array('min weight', $headers, true));
    }
    private function is_tools_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && in_array('цена без скидки', $headers, true)
            && ! in_array('вес упаковки', $headers, true)
            && ! in_array('вес, гр', $headers, true)
            && ! in_array('подкатегория', $headers, true)
            && ! in_array('тип наращивания', $headers, true)
            && ! in_array('качество волос', $headers, true)
            && ! in_array('цветовая группа', $headers, true);
    }
    private function is_keratin_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && (in_array('вес упаковки', $headers, true) || in_array('вес, гр', $headers, true) || in_array('подкатегория', $headers, true));
    }
    private function normalize_header(mixed $value): string
    {
        $value = is_string($value) ? $value : (string) $value;
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }
    private function normalize_custom_hair_key(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (function_exists('nice_hair_normalize_shop_key')) {
            return (string) nice_hair_normalize_shop_key($value);
        }

        return str_replace('-', '_', sanitize_title($value));
    }
    private function normalize_custom_hair_numeric_key(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/\d+(?:[.,]\d+)?/', $value, $matches)) {
            $number = str_replace(',', '.', (string) $matches[0]);

            if (is_numeric($number)) {
                $float = (float) $number;

                return floor($float) === $float
                    ? number_format($float, 0, '.', '')
                    : rtrim(rtrim(number_format($float, 2, '.', ''), '0'), '.');
            }
        }

        return $this->normalize_custom_hair_key($value);
    }
    private function get_row_value(array $row, array $headerMap, string $header): string
    {
        if (! isset($headerMap[$header])) {
            return '';
        }

        $index = (int) $headerMap[$header];
        $value = $row[$index] ?? '';

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
    private function get_first_row_value(array $row, array $headerMap, array $headers): string
    {
        foreach ($headers as $header) {
            $value = $this->get_row_value($row, $headerMap, (string) $header);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
