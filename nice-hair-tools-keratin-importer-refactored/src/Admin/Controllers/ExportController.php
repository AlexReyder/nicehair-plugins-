<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ExportController
{
    public static function handle_export_request(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Недостаточно прав для экспорта товаров.', 'nice-hair-tools-keratin-importer'));
        }

        $family = self::normalize_family(isset($_GET['nh_tki_family']) ? (string) wp_unslash($_GET['nh_tki_family']) : 'tools');
        check_admin_referer(self::EXPORT_ACTION . '_' . $family);

        try {
            $export = (new NH_TKI_Importer())->export_family($family);
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }

        $filename = isset($export['filename']) && is_string($export['filename'])
            ? $export['filename']
            : 'nice-hair-products.xlsx';
        $content = isset($export['content']) && is_string($export['content'])
            ? $export['content']
            : '';

        if ($content === '') {
            wp_die(esc_html__('Не удалось подготовить файл экспорта.', 'nice-hair-tools-keratin-importer'));
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
}
