<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_AttachmentCleanupTrait
{
    private function cleanup_stale_context_attachments(string $contextKey, int $postId, array $currentAssetKeys, array $currentImageIds, array &$report): void
    {
        if ($contextKey === '') {
            return;
        }

        $attachmentIds = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => NH_TKI_Plugin::META_ASSET_KEY,
                    'value' => $contextKey . '|',
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        if (! is_array($attachmentIds) || $attachmentIds === []) {
            return;
        }

        $activeAssetKeys = array_fill_keys(array_map('strval', $currentAssetKeys), true);
        $activeImageIds = array_fill_keys(array_map('intval', $currentImageIds), true);

        foreach ($attachmentIds as $attachmentId) {
            $attachmentId = (int) $attachmentId;

            if ($attachmentId <= 0 || isset($activeImageIds[$attachmentId])) {
                continue;
            }

            if ((int) get_post_field('post_parent', $attachmentId) !== $postId) {
                continue;
            }

            $assetKey = (string) get_post_meta($attachmentId, NH_TKI_Plugin::META_ASSET_KEY, true);

            if ($assetKey !== '' && isset($activeAssetKeys[$assetKey])) {
                continue;
            }

            if (wp_delete_attachment($attachmentId, true)) {
                $this->bump_media_stat($report, 'deleted');
            }
        }
    }
}
