<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_MetaServiceTrait
{
    private function update_video_meta(int $postId, string $videoUrl): void
    {
        if (function_exists('update_field')) {
            update_field(NH_TKI_Plugin::VIDEO_FIELD_KEY, $videoUrl, $postId);
        } else {
            update_post_meta($postId, 'nh_product_video_url', $videoUrl);
        }
    }
    private function update_unique_item_meta(int $postId, bool $isUnique): void
    {
        $value = $isUnique ? 1 : 0;

        if (function_exists('update_field')) {
            update_field('field_nh_unique_item', $value, $postId);
        } else {
            update_post_meta($postId, 'nh_unique_item', $value);
        }
    }
    private function update_exclusive_pricing_meta(int $postId, string $baseLotPrice, string $fixedWeightGrams, mixed $compareAtLotPrice = null): void
    {
        $baseLotPrice = $this->parse_positive_number($baseLotPrice);
        $fixedWeightGrams = $this->parse_positive_number($fixedWeightGrams);
        $compareAtLotPrice = $this->parse_positive_number((string) ($compareAtLotPrice ?? ''));

        if ($baseLotPrice !== null) {
            $value = (float) $baseLotPrice;

            if (function_exists('update_field')) {
                update_field('field_nh_base_lot_price', $value, $postId);
            } else {
                update_post_meta($postId, 'nh_base_lot_price', $value);
            }
        }

        if ($fixedWeightGrams !== null) {
            $value = (float) $fixedWeightGrams;

            if (function_exists('update_field')) {
                update_field('field_nh_fixed_weight_grams', $value, $postId);
            } else {
                update_post_meta($postId, 'nh_fixed_weight_grams', $value);
            }
        }

        // Compare-at / old price for Exclusive Hair is now stored in WooCommerce
        // regular_price when a sale_price exists. Clear the legacy custom meta to avoid
        // stale frontend data after re-importing products created by older plugin versions.
        delete_post_meta($postId, 'nh_compare_at_lot_price');
    }
    private function update_custom_hair_config_meta(
        int $postId,
        array $item,
        array $imageIdByFile,
        array $imageIds,
        array $existingColorImageMap
    ): void {
        $this->update_custom_hair_field($postId, 'field_nh_custom_hair_available_lengths', 'nh_custom_hair_available_lengths', array_values((array) ($item['allowed_lengths'] ?? [])));
        $this->update_custom_hair_field($postId, 'field_nh_custom_hair_available_qualities', 'nh_custom_hair_available_qualities', array_values((array) ($item['allowed_qualities'] ?? [])));
        $this->update_custom_hair_field($postId, 'field_nh_custom_hair_available_textures', 'nh_custom_hair_available_textures', array_values((array) ($item['allowed_textures'] ?? [])));
        $this->update_custom_hair_field($postId, 'field_nh_custom_hair_min_weight_grams', 'nh_custom_hair_min_weight_grams', (int) ($item['min_weight_grams'] ?? 30));
        $this->update_custom_hair_field($postId, 'field_nh_custom_hair_weight_step_grams', 'nh_custom_hair_weight_step_grams', (int) ($item['weight_step_grams'] ?? 10));
        $this->update_custom_hair_field($postId, 'field_nh_custom_hair_default_weight_grams', 'nh_custom_hair_default_weight_grams', (int) ($item['default_weight_grams'] ?? 30));

        if (empty($item['color_options_provided'])) {
            return;
        }

        $selectedGlobalColorKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            (array) ($item['selected_global_color_keys'] ?? [])
        ))));

        $this->update_custom_hair_selected_global_colors_field($postId, $selectedGlobalColorKeys);
    }
    private function update_custom_hair_selected_global_colors_field(int $postId, array $keys): void
    {
        $keys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $keys
        ))));

        if (function_exists('update_field')) {
            update_field('field_nh_product_custom_hair_selected_global_colors', $keys, $postId);

            return;
        }

        update_post_meta($postId, 'nh_custom_hair_selected_global_colors', $keys);
    }
    private function update_custom_hair_field(int $postId, string $fieldKey, string $fieldName, mixed $value): void
    {
        if (function_exists('update_field')) {
            update_field($fieldKey, $value, $postId);
        } else {
            update_post_meta($postId, $fieldName, $value);
        }
    }
    private function update_custom_hair_color_options_field(int $postId, array $rows): void
    {
        if (function_exists('update_field')) {
            update_field('field_nh_custom_hair_color_options', $rows, $postId);

            return;
        }

        $oldCount = (int) get_post_meta($postId, 'nh_custom_hair_color_options', true);

        for ($index = 0; $index < $oldCount; $index++) {
            delete_post_meta($postId, 'nh_custom_hair_color_options_' . $index . '_color_label');
            delete_post_meta($postId, 'nh_custom_hair_color_options_' . $index . '_color_value');
            delete_post_meta($postId, 'nh_custom_hair_color_options_' . $index . '_color_group');
            delete_post_meta($postId, 'nh_custom_hair_color_options_' . $index . '_main_image');
        }

        update_post_meta($postId, 'nh_custom_hair_color_options', count($rows));

        foreach (array_values($rows) as $index => $row) {
            update_post_meta($postId, 'nh_custom_hair_color_options_' . $index . '_color_label', (string) ($row['color_label'] ?? ''));
            update_post_meta($postId, 'nh_custom_hair_color_options_' . $index . '_color_value', (string) ($row['color_value'] ?? ''));
            update_post_meta($postId, 'nh_custom_hair_color_options_' . $index . '_color_group', (string) ($row['color_group'] ?? ''));
            update_post_meta($postId, 'nh_custom_hair_color_options_' . $index . '_main_image', (string) ($row['main_image'] ?? ''));
        }
    }
    private function get_existing_custom_hair_color_image_map(int $postId): array
    {
        $map = [];

        foreach ($this->get_custom_hair_color_option_rows($postId) as $row) {
            $label = (string) ($row['color_label'] ?? '');
            $value = (string) ($row['color_value'] ?? '');
            $key = $this->normalize_custom_hair_key($value !== '' ? $value : $label);
            $imageId = $this->get_attachment_id_from_media_field($row['main_image'] ?? null);

            if ($key !== '' && $imageId > 0) {
                $map[$key] = $imageId;
            }
        }

        return $map;
    }
    private function build_imported_image_id_map(array $imageEntries, string $contextKey): array
    {
        $map = [];

        foreach ($imageEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryName = (string) ($entry['name'] ?? '');

            if ($entryName === '') {
                continue;
            }

            $assetKey = $contextKey . '|' . md5($entryName);
            $attachmentId = $this->find_attachment_by_asset_key($assetKey);

            if ($attachmentId <= 0) {
                continue;
            }

            $normalizedName = strtolower(str_replace('\\', '/', $entryName));
            $map[$normalizedName] = $attachmentId;
            $map[strtolower(basename($entryName))] = $attachmentId;
        }

        return $map;
    }
    private function get_custom_hair_color_option_image_id(string $photoFile, array $imageIdByFile): int
    {
        $photoFile = str_replace('\\', '/', trim($photoFile));

        if ($photoFile === '') {
            return 0;
        }

        foreach (array_unique([
            strtolower($photoFile),
            strtolower(basename($photoFile)),
        ]) as $key) {
            if (isset($imageIdByFile[$key])) {
                return (int) $imageIdByFile[$key];
            }
        }

        return 0;
    }
}
