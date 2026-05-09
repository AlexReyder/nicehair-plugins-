<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_AttachmentImporterTrait
{
    private function import_images_for_key(array $entries, string $contextKey, int $postId, string $reportSku, array &$report): array
    {
        $imageIds = [];
        $currentAssetKeys = [];
        $zip = new ZipArchive();
        $zipFile = isset($report['__zip_path']) && is_string($report['__zip_path'])
            ? $report['__zip_path']
            : '';

        if ($zipFile === '' || $zip->open($zipFile) !== true) {
            return $imageIds;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($entries as $entry) {
            $entryName = (string) ($entry['name'] ?? '');

            if ($entryName === '') {
                continue;
            }

            $assetKey = $contextKey . '|' . md5($entryName);
            $currentAssetKeys[] = $assetKey;
            $existingAttachmentId = $this->find_attachment_by_asset_key($assetKey);
            $source = $this->read_zip_image_source($zip, $entryName, $reportSku, $report);

            if (! is_array($source)) {
                $this->maybe_preserve_existing_attachment($existingAttachmentId, $assetKey, $contextKey, null, $imageIds, $report);
                continue;
            }

            if ($existingAttachmentId > 0 && ! $this->attachment_requires_refresh($existingAttachmentId, (string) $source['hash'])) {
                $this->update_attachment_import_meta($existingAttachmentId, $assetKey, $contextKey, (string) $source['hash']);
                $imageIds[] = $existingAttachmentId;
                $this->bump_media_stat($report, 'reused');
                continue;
            }

            $prepared = $this->prepare_image_source_for_upload($source, $reportSku, $report);

            if (! is_array($prepared)) {
                $this->maybe_preserve_existing_attachment($existingAttachmentId, $assetKey, $contextKey, null, $imageIds, $report);
                continue;
            }

            // Generate only the sizes the current theme/Woo storefront actually uses.
            add_filter('intermediate_image_sizes_advanced', [self::class, 'limit_intermediate_image_sizes'], 999);
            add_filter('big_image_size_threshold', '__return_false', 999);

            try {
                if ($existingAttachmentId > 0) {
                    $attachmentId = $this->replace_attachment_file($existingAttachmentId, $prepared, $postId);
                } else {
                    $attachmentId = media_handle_sideload([
                        'name' => $prepared['name'],
                        'tmp_name' => $prepared['tmp_name'],
                    ], $postId);
                }
            } finally {
                remove_filter('intermediate_image_sizes_advanced', [self::class, 'limit_intermediate_image_sizes'], 999);
                remove_filter('big_image_size_threshold', '__return_false', 999);
            }

            @unlink($prepared['tmp_name']);

            if (is_wp_error($attachmentId)) {
                $report['unsupported_images'][] = [
                    'sku' => $reportSku,
                    'file' => $entryName,
                    'reason' => $attachmentId->get_error_message(),
                ];
                $this->maybe_preserve_existing_attachment($existingAttachmentId, $assetKey, $contextKey, null, $imageIds, $report);
                continue;
            }

            $this->update_attachment_import_meta((int) $attachmentId, $assetKey, $contextKey, (string) $source['hash']);
            $imageIds[] = (int) $attachmentId;
            $this->bump_media_stat($report, $existingAttachmentId > 0 ? 'refreshed' : 'created');
        }

        $zip->close();
        $this->cleanup_stale_context_attachments($contextKey, $postId, $currentAssetKeys, $imageIds, $report);

        return array_values(array_unique(array_map('intval', $imageIds)));
    }
    private function maybe_preserve_existing_attachment(int $attachmentId, string $assetKey, string $contextKey, ?string $sourceHash, array &$imageIds, array &$report): bool
    {
        if ($attachmentId <= 0 || ! $this->attachment_file_exists($attachmentId)) {
            return false;
        }

        $this->update_attachment_import_meta($attachmentId, $assetKey, $contextKey, $sourceHash);
        $imageIds[] = $attachmentId;
        $this->bump_media_stat($report, 'reused');

        return true;
    }
    private function attachment_requires_refresh(int $attachmentId, string $sourceHash): bool
    {
        if (! $this->attachment_file_exists($attachmentId)) {
            return true;
        }

        $storedHash = (string) get_post_meta($attachmentId, NH_TKI_Plugin::META_ASSET_SOURCE_HASH, true);
        $storedVersion = (int) get_post_meta($attachmentId, NH_TKI_Plugin::META_ASSET_PIPELINE_VERSION, true);
        $metadata = wp_get_attachment_metadata($attachmentId);

        if ($storedHash === '' || ! hash_equals($storedHash, $sourceHash)) {
            return true;
        }

        if ($storedVersion < self::IMAGE_PIPELINE_VERSION) {
            return true;
        }

        return ! is_array($metadata) || $metadata === [];
    }
    private function attachment_file_exists(int $attachmentId): bool
    {
        $attachedFile = get_attached_file($attachmentId);

        return is_string($attachedFile) && $attachedFile !== '' && file_exists($attachedFile);
    }
    private function update_attachment_import_meta(int $attachmentId, string $assetKey, string $contextKey, ?string $sourceHash): void
    {
        update_post_meta($attachmentId, NH_TKI_Plugin::META_ASSET_KEY, $assetKey);
        update_post_meta($attachmentId, NH_TKI_Plugin::META_ASSET_CONTEXT, $contextKey);

        if ($sourceHash !== null && $sourceHash !== '') {
            update_post_meta($attachmentId, NH_TKI_Plugin::META_ASSET_SOURCE_HASH, $sourceHash);
            update_post_meta($attachmentId, NH_TKI_Plugin::META_ASSET_PIPELINE_VERSION, self::IMAGE_PIPELINE_VERSION);
        }
    }

    // Keep attachment IDs stable so existing product references survive re-imports.
    private function replace_attachment_file(int $attachmentId, array $prepared, int $postId): int|WP_Error
    {
        $oldFile = get_attached_file($attachmentId);
        $oldMetadata = wp_get_attachment_metadata($attachmentId);
        $handled = wp_handle_sideload([
            'name' => (string) ($prepared['name'] ?? ''),
            'tmp_name' => (string) ($prepared['tmp_name'] ?? ''),
        ], [
            'test_form' => false,
        ]);

        if (! is_array($handled) || isset($handled['error'])) {
            return new WP_Error(
                'nh_tki_attachment_replace_failed',
                is_array($handled) ? (string) ($handled['error'] ?? 'Unable to replace attachment file.') : 'Unable to replace attachment file.'
            );
        }

        $newFile = (string) ($handled['file'] ?? '');
        $newUrl = (string) ($handled['url'] ?? '');
        $newMimeType = (string) ($handled['type'] ?? '');
        $title = preg_replace('/\.[^.]+$/', '', wp_basename((string) ($prepared['name'] ?? '')));
        $updated = wp_update_post([
            'ID' => $attachmentId,
            'post_parent' => $postId,
            'post_mime_type' => $newMimeType,
            'guid' => $newUrl,
            'post_title' => sanitize_text_field((string) $title),
        ], true);

        if (is_wp_error($updated)) {
            if ($newFile !== '' && file_exists($newFile)) {
                wp_delete_file($newFile);
            }

            return $updated;
        }

        update_attached_file($attachmentId, $newFile);
        $metadata = wp_generate_attachment_metadata($attachmentId, $newFile);
        wp_update_attachment_metadata($attachmentId, is_array($metadata) ? $metadata : []);
        $this->delete_attachment_files($oldFile, $oldMetadata, $newFile);

        return $attachmentId;
    }
    private function delete_attachment_files(mixed $oldFile, mixed $oldMetadata, string $currentFile): void
    {
        if (! is_string($oldFile) || $oldFile === '') {
            return;
        }

        $paths = [$oldFile];
        $baseDir = dirname($oldFile);

        if (is_array($oldMetadata)) {
            $originalImage = $oldMetadata['original_image'] ?? '';

            if (is_string($originalImage) && $originalImage !== '') {
                $paths[] = path_join($baseDir, $originalImage);
            }

            if (isset($oldMetadata['sizes']) && is_array($oldMetadata['sizes'])) {
                foreach ($oldMetadata['sizes'] as $size) {
                    $sizeFile = is_array($size) ? (string) ($size['file'] ?? '') : '';

                    if ($sizeFile !== '') {
                        $paths[] = path_join($baseDir, $sizeFile);
                    }
                }
            }
        }

        $currentPath = wp_normalize_path($currentFile);

        foreach (array_unique($paths) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (wp_normalize_path($path) === $currentPath || ! file_exists($path)) {
                continue;
            }

            wp_delete_file($path);
        }
    }
    private function bump_media_stat(array &$report, string $key, int $amount = 1): void
    {
        if (! isset($report['media_stats']) || ! is_array($report['media_stats'])) {
            return;
        }

        $report['media_stats'][$key] = (int) ($report['media_stats'][$key] ?? 0) + $amount;
    }
    private function find_attachment_by_asset_key(string $assetKey): int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => NH_TKI_Plugin::META_ASSET_KEY,
            'meta_value' => $assetKey,
            'no_found_rows' => true,
        ]);

        return isset($ids[0]) ? (int) $ids[0] : 0;
    }
}
