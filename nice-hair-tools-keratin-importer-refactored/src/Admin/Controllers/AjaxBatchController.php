<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_AjaxBatchController
{
    public static function handle_process_run_ajax(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error([
                'message' => 'Недостаточно прав для запуска импорта.',
            ], 403);
        }

        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        $runId = isset($_POST['run_id']) ? sanitize_key((string) wp_unslash($_POST['run_id'])) : '';
        $batchSize = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 1;

        if ($runId === '') {
            wp_send_json_error([
                'message' => 'Не найден идентификатор импорта.',
            ], 400);
        }

        try {
            $importer = new NH_TKI_Importer();
            $status = $importer->process_import_run($runId, max(1, min(3, $batchSize)));
        } catch (Throwable $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage(),
            ], 500);
        }

        $response = [
            'done' => ! empty($status['done']),
            'processed' => (int) ($status['processed'] ?? 0),
            'total' => (int) ($status['total'] ?? 0),
            'progress_label' => sprintf(
                'Обработано %d из %d элементов.',
                (int) ($status['processed'] ?? 0),
                (int) ($status['total'] ?? 0)
            ),
        ];

        if (! empty($status['done']) && isset($status['report']) && is_array($status['report'])) {
            $response['report_html'] = self::capture_report_html($status['report']);
        }

        wp_send_json_success($response);
    }
}
