<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_CustomHairRowParserTrait
{
    private function parse_custom_hair_rows(array $rows, array $headers, int $rowOffset = 0): array
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
                $errors[] = sprintf('Custom Hair row %d: title or SKU is missing.', $rowNumber);
                continue;
            }

            $regularPrice = $this->parse_price($this->get_row_value($row, $map, 'цена без скидки'));
            $salePrice = $this->parse_price($this->get_row_value($row, $map, 'цена со скидкой'));

            if ($regularPrice === null) {
                $errors[] = sprintf('Custom Hair row %d (%s): regular price is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            $extensionType = $this->get_row_value($row, $map, 'тип наращивания');

            if ($extensionType === '') {
                $errors[] = sprintf('Custom Hair row %d (%s): extension type is missing.', $rowNumber, $sku);
                continue;
            }

            $colorParse = $this->parse_custom_hair_color_options(
                $this->get_row_value($row, $map, 'цветовые опции'),
                $rowNumber,
                $sku
            );

            if ($colorParse['errors'] !== []) {
                $errors = array_merge($errors, $colorParse['errors']);
                continue;
            }

            if (($colorParse['warnings'] ?? []) !== []) {
                $warnings = array_merge($warnings, $colorParse['warnings']);
            }

            $weightConfig = $this->parse_custom_hair_weight_config($row, $map, $rowNumber, $sku);

            if ($weightConfig['errors'] !== []) {
                $errors = array_merge($errors, $weightConfig['errors']);
                continue;
            }

            $choiceParse = $this->parse_custom_hair_choice_fields($row, $map, $rowNumber, $sku);

            if ($choiceParse['errors'] !== []) {
                $errors = array_merge($errors, $choiceParse['errors']);
                continue;
            }

            $stockValue = $this->parse_boolean_field($this->get_row_value($row, $map, 'в наличии'), true);
            $featuredValue = $this->parse_boolean_field($this->get_row_value($row, $map, 'featured'), false);
            $status = strtolower($this->get_row_value($row, $map, 'статус'));
            $status = $status === '' ? 'publish' : $status;

            if ($stockValue === null) {
                $errors[] = sprintf('Custom Hair row %d (%s): stock value must be yes or no.', $rowNumber, $sku);
                continue;
            }

            if ($featuredValue === null) {
                $errors[] = sprintf('Custom Hair row %d (%s): Featured value must be yes or no.', $rowNumber, $sku);
                continue;
            }

            if (! in_array($status, ['publish', 'draft'], true)) {
                $errors[] = sprintf('Custom Hair row %d (%s): status must be publish or draft.', $rowNumber, $sku);
                continue;
            }

            $items[] = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'attributes' => [
                    'pa_extension_type' => $extensionType,
                ],
                'allowed_lengths' => $choiceParse['lengths'],
                'allowed_qualities' => $choiceParse['qualities'],
                'allowed_textures' => $choiceParse['textures'],
                'min_weight_grams' => $weightConfig['min'],
                'weight_step_grams' => $weightConfig['step'],
                'default_weight_grams' => $weightConfig['default'],
                'color_options' => $colorParse['options'],
                'color_options_provided' => (bool) ($colorParse['provided'] ?? false),
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
    private function parse_custom_hair_choice_fields(array $row, array $map, int $rowNumber, string $sku): array
    {
        $errors = [];
        $lengths = $this->parse_custom_hair_choice_list(
            $this->get_first_row_value($row, $map, ['доступные длины', 'доступные длины, см']),
            $this->get_custom_hair_length_choice_map(),
            true,
            'lengths',
            $rowNumber,
            $sku,
            $errors
        );
        $qualities = $this->parse_custom_hair_choice_list(
            $this->get_first_row_value($row, $map, ['доступные качества', 'доступные качества волос']),
            $this->get_custom_hair_quality_choice_map(),
            false,
            'qualities',
            $rowNumber,
            $sku,
            $errors
        );
        $textures = $this->parse_custom_hair_choice_list(
            $this->get_first_row_value($row, $map, ['доступные текстуры', 'текстуры']),
            $this->get_custom_hair_texture_choice_map(),
            false,
            'textures',
            $rowNumber,
            $sku,
            $errors
        );

        return [
            'lengths' => $lengths,
            'qualities' => $qualities,
            'textures' => $textures,
            'errors' => $errors,
        ];
    }
    private function parse_custom_hair_choice_list(
        string $value,
        array $choiceMap,
        bool $numericKeys,
        string $fieldLabel,
        int $rowNumber,
        string $sku,
        array &$errors
    ): array {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[\r\n;,]+/u', $value) ?: [];
        $keys = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);

            if ($part === '') {
                continue;
            }

            $key = $this->resolve_custom_hair_choice_key($part, $choiceMap, $numericKeys);

            if ($key === '') {
                $errors[] = sprintf('Custom Hair row %d (%s): unknown %s value "%s".', $rowNumber, $sku, $fieldLabel, $part);
                continue;
            }

            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }
    private function resolve_custom_hair_choice_key(string $value, array $choiceMap, bool $numericKeys = false): string
    {
        $normalized = $numericKeys
            ? $this->normalize_custom_hair_numeric_key($value)
            : $this->normalize_custom_hair_key($value);

        if ($normalized !== '' && isset($choiceMap[$normalized])) {
            return $normalized;
        }

        foreach ($choiceMap as $key => $label) {
            $key = (string) $key;
            $label = (string) $label;
            $candidate = $numericKeys
                ? $this->normalize_custom_hair_numeric_key($label)
                : $this->normalize_custom_hair_key($label);

            if ($candidate !== '' && $candidate === $normalized) {
                return $key;
            }
        }

        return '';
    }
    private function parse_custom_hair_weight_config(array $row, array $map, int $rowNumber, string $sku): array
    {
        $errors = [];
        $min = $this->parse_custom_hair_integer(
            $this->get_first_row_value($row, $map, ['мин. вес, гр', 'мин вес, гр', 'min weight']),
            30,
            0
        );
        $step = $this->parse_custom_hair_integer(
            $this->get_first_row_value($row, $map, ['шаг веса, гр', 'шаг веса гр', 'weight step']),
            10,
            1
        );
        $default = $this->parse_custom_hair_integer(
            $this->get_first_row_value($row, $map, ['вес по умолчанию, гр', 'вес по умолчанию гр', 'default weight']),
            30,
            0
        );

        if ($min === null) {
            $errors[] = sprintf('Custom Hair row %d (%s): min weight is invalid.', $rowNumber, $sku);
        }

        if ($step === null) {
            $errors[] = sprintf('Custom Hair row %d (%s): weight step is invalid.', $rowNumber, $sku);
        }

        if ($default === null) {
            $errors[] = sprintf('Custom Hair row %d (%s): default weight is invalid.', $rowNumber, $sku);
        }

        if ($errors === [] && $default < $min) {
            $errors[] = sprintf('Custom Hair row %d (%s): default weight must be greater than or equal to min weight.', $rowNumber, $sku);
        }

        if ($errors === [] && (($default - $min) % $step) !== 0) {
            $errors[] = sprintf('Custom Hair row %d (%s): default weight must align with min weight and step.', $rowNumber, $sku);
        }

        return [
            'min' => $min ?? 30,
            'step' => $step ?? 10,
            'default' => $default ?? 30,
            'errors' => $errors,
        ];
    }
    private function parse_custom_hair_integer(string $value, int $default, int $minimum): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return $default;
        }

        $normalized = str_replace([' ', "\xc2\xa0"], '', $value);
        $normalized = preg_replace('/[^0-9.]+/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $number = (int) round((float) $normalized);

        return $number >= $minimum ? $number : null;
    }
    private function parse_custom_hair_color_options(string $value, int $rowNumber, string $sku): array
    {
        $value = trim($value);
        $errors = [];
        $warnings = [];
        $options = [];

        if ($value === '') {
            return [
                'options' => [],
                'provided' => false,
                'warnings' => [
                    sprintf(
                        'Custom Hair row %d (%s): color options are empty; importer will not change them. New products need color options added manually before purchase.',
                        $rowNumber,
                        $sku
                    ),
                ],
                'errors' => [],
            ];
        }

        $parts = preg_split('/[;\r\n]+/u', $value) ?: [];
        $seen = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);

            if ($part === '') {
                continue;
            }

            $fields = array_map('trim', explode('|', $part));
            $label = (string) ($fields[0] ?? '');
            $rawValue = (string) ($fields[1] ?? '');
            $group = (string) ($fields[2] ?? '');
            $photoFile = (string) ($fields[3] ?? '');

            if ($label === '') {
                $errors[] = sprintf('Custom Hair row %d (%s): color option label is missing.', $rowNumber, $sku);
                continue;
            }

            if ($rawValue === '') {
                $rawValue = str_starts_with($label, '#') ? ltrim($label, '#') : $label;
            }

            $groupKey = $this->normalize_custom_hair_color_group($group);

            if ($group !== '' && $groupKey === '') {
                $errors[] = sprintf('Custom Hair row %d (%s): unknown color group "%s".', $rowNumber, $sku, $group);
                continue;
            }

            $key = $this->normalize_custom_hair_key($rawValue !== '' ? $rawValue : $label);

            if ($key === '') {
                $errors[] = sprintf('Custom Hair row %d (%s): color option value is invalid.', $rowNumber, $sku);
                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = sprintf('Custom Hair row %d (%s): duplicate color option "%s".', $rowNumber, $sku, $label);
                continue;
            }

            $seen[$key] = true;
            $options[] = [
                'key' => $key,
                'label' => $label,
                'value' => $rawValue,
                'group' => $groupKey,
                'photo_file' => $photoFile,
            ];
        }

        if ($options === [] && $errors === []) {
            $errors[] = sprintf('Custom Hair row %d (%s): color options format is invalid.', $rowNumber, $sku);
        }

        return [
            'options' => $options,
            'provided' => true,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
    private function normalize_custom_hair_color_group(string $value): string
    {
        $raw = mb_strtolower(trim($value));
        $key = $this->normalize_custom_hair_key($value);
        $aliases = [
            'light' => 'light',
            'svetlaya' => 'light',
            'svetlyy' => 'light',
            'светлая' => 'light',
            'светлый' => 'light',
            'middle' => 'middle',
            'medium' => 'middle',
            'srednyaya' => 'middle',
            'sredniy' => 'middle',
            'средняя' => 'middle',
            'средний' => 'middle',
            'dark' => 'dark',
            'temnaya' => 'dark',
            'temnyy' => 'dark',
            'темная' => 'dark',
            'тёмная' => 'dark',
            'темный' => 'dark',
            'тёмный' => 'dark',
        ];

        if (isset($aliases[$raw])) {
            return $aliases[$raw];
        }

        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        return isset($this->get_custom_hair_color_group_choice_map()[$key]) ? $key : '';
    }
}
