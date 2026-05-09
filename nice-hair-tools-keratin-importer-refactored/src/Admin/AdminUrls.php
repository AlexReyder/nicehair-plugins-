<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_AdminUrls
{
    private static function should_show_server_source_mode(): bool
    {
        return current_user_can('manage_options')
            && isset($_GET['nh_tki_server_mode'])
            && (string) wp_unslash($_GET['nh_tki_server_mode']) === '1';
    }
    private static function get_import_tabs(): array
    {
        return [
            'tools' => 'Tools',
            'keratin' => 'Keratin',
            'ready_to_install' => 'Ready to Install',
            'exclusive_hair' => 'Exclusive Hair',
            'custom_hair' => 'Custom Hair',
        ];
    }
    private static function normalize_family(string $family): string
    {
        $family = sanitize_key($family);

        return array_key_exists($family, self::get_import_tabs()) ? $family : 'tools';
    }
    private static function get_active_family(): string
    {
        if (isset($_POST['nh_tki_family'])) {
            return self::normalize_family((string) wp_unslash($_POST['nh_tki_family']));
        }

        if (isset($_GET['nh_tki_family'])) {
            return self::normalize_family((string) wp_unslash($_GET['nh_tki_family']));
        }

        return 'tools';
    }
    private static function get_family_label(string $family): string
    {
        $tabs = self::get_import_tabs();

        return (string) ($tabs[self::normalize_family($family)] ?? 'Tools');
    }
    private static function get_admin_page_url(string $family, bool $showServerMode = false): string
    {
        $args = [
            'page' => self::PAGE_SLUG,
            'nh_tki_family' => self::normalize_family($family),
        ];

        if ($showServerMode) {
            $args['nh_tki_server_mode'] = '1';
        }

        return add_query_arg($args, admin_url(self::PAGE_PARENT_SLUG));
    }
    private static function get_export_url(string $family): string
    {
        $family = self::normalize_family($family);
        $url = add_query_arg([
            'action' => self::EXPORT_ACTION,
            'nh_tki_family' => $family,
        ], admin_url('admin-post.php'));

        return wp_nonce_url($url, self::EXPORT_ACTION . '_' . $family);
    }
    private static function get_template_url(string $family): string
    {
        $family = self::normalize_family($family);
        $url = add_query_arg([
            'action' => self::TEMPLATE_ACTION,
            'nh_tki_family' => $family,
        ], admin_url('admin-post.php'));

        return wp_nonce_url($url, self::TEMPLATE_ACTION . '_' . $family);
    }
    private static function get_effective_upload_limit(): int
    {
        $limits = array_filter([
            (int) wp_convert_hr_to_bytes((string) ini_get('upload_max_filesize')),
            (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size')),
        ], static fn (int $limit): bool => $limit > 0);

        return $limits === [] ? 0 : min($limits);
    }
    private static function format_bytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $index = 0;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return sprintf($index === 0 ? '%.0f %s' : '%.1f %s', $size, $units[$index]);
    }
}
