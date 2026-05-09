<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_AdminReportView
{
    private static function render_batch_runner(array $run): void
    {
        $runId = isset($run['run_id']) && is_string($run['run_id']) ? $run['run_id'] : '';
        $total = (int) ($run['total'] ?? 0);
        $ajaxUrl = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce(self::AJAX_ACTION);

        if ($runId === '' || $total <= 0) {
            if (isset($run['report']) && is_array($run['report'])) {
                self::render_report($run['report']);
            }

            return;
        }

        ?>
        <div class="notice notice-info">
            <p><strong>Импорт запущен в безопасном batch-режиме.</strong> Hostinger будет обрабатывать товары по очереди, чтобы не уронить запрос по таймауту.</p>
        </div>

        <div id="nh-tki-runner"
             data-run-id="<?php echo esc_attr($runId); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>"
             data-total="<?php echo esc_attr((string) $total); ?>"
             data-ajax-url="<?php echo esc_url($ajaxUrl); ?>"
             data-action="<?php echo esc_attr(self::AJAX_ACTION); ?>">
            <p id="nh-tki-progress-text">Подготовка batch-импорта…</p>
            <progress id="nh-tki-progress-bar" max="<?php echo esc_attr((string) $total); ?>" value="0" style="width: 100%; max-width: 640px;"></progress>
            <div id="nh-tki-report" style="margin-top: 16px;"></div>
        </div>

        <?php
        wp_enqueue_script(
            'nh-tki-batch-runner',
            plugins_url('assets/admin/batch-runner.js', NH_TKI_PLUGIN_FILE),
            [],
            NH_TKI_PLUGIN_VERSION,
            true
        );
    }
    private static function capture_report_html(array $report): string
    {
        ob_start();
        self::render_report($report);

        return (string) ob_get_clean();
    }
    private static function render_report(array $report): void
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
        $warnings = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
        $messages = is_array($report['messages'] ?? null) ? $report['messages'] : [];
        $products = is_array($report['products'] ?? null) ? $report['products'] : [];
        $unsupported = is_array($report['unsupported_images'] ?? null) ? $report['unsupported_images'] : [];
        $missing = is_array($report['missing_images'] ?? null) ? $report['missing_images'] : [];
        $unmatched = is_array($report['unmatched_images'] ?? null) ? $report['unmatched_images'] : [];

        if ($errors !== []) {
            echo '<div class="notice notice-error"><p>' . esc_html(implode(' | ', $errors)) . '</p></div>';
        }

        if ($messages !== []) {
            echo '<div class="notice notice-success"><p>' . esc_html(implode(' | ', $messages)) . '</p></div>';
        }

        if ($warnings !== []) {
            echo '<div class="notice notice-warning"><p>' . esc_html(implode(' | ', $warnings)) . '</p></div>';
        }

        if ($summary !== []) {
            echo '<h2>Сводка</h2>';
            echo '<table class="widefat striped" style="max-width: 900px"><tbody>';

            foreach ($summary as $label => $value) {
                echo '<tr><th style="width:260px">' . esc_html((string) $label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
            }

            echo '</tbody></table>';
        }

        if ($products !== []) {
            echo '<h2>Товары</h2>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>Family</th><th>Тип</th><th>Название</th><th>SKU / key</th><th>Действие</th><th>Фото</th><th>Примечание</th>';
            echo '</tr></thead><tbody>';

            foreach ($products as $item) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['family'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['kind'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['title'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['key'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['action'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['images'] ?? '0')) . '</td>';
                echo '<td>' . esc_html((string) ($item['note'] ?? '')) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        if ($missing !== []) {
            echo '<h2>Товары без найденных фото</h2><table class="widefat striped"><thead><tr><th>Family</th><th>Название</th><th>Ключ</th></tr></thead><tbody>';

            foreach ($missing as $item) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['family'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['title'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['key'] ?? '')) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        if ($unsupported !== []) {
            echo '<h2>Неподдержанные / не сконвертированные изображения</h2><table class="widefat striped"><thead><tr><th>SKU</th><th>Файл</th><th>Причина</th></tr></thead><tbody>';

            foreach ($unsupported as $item) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['sku'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['file'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['reason'] ?? '')) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        if ($unmatched !== []) {
            echo '<h2>Фотографии без SKU в Excel</h2><table class="widefat striped"><thead><tr><th>Ключ</th><th>Файлы</th></tr></thead><tbody>';

            foreach ($unmatched as $item) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['key'] ?? '')) . '</td>';
                echo '<td>' . esc_html(implode(', ', array_map('strval', (array) ($item['files'] ?? [])))) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }
}
