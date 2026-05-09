<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ImportReportTrait
{
    private function build_base_report(string $mode, array $normalized, array $photoIndex, string $family = 'all'): array
    {
        $summary = [
            'Режим' => $mode === 'preview' ? 'Preview' : 'Import',
            'Category' => $family === 'all' ? 'All' : ucfirst($family),
            'Tools rows' => count((array) ($normalized['tools'] ?? [])),
            'Keratin rows' => count((array) ($normalized['keratin_rows'] ?? [])),
            'Keratin groups' => count((array) ($normalized['keratin_groups'] ?? [])),
            'Ready to Install rows' => count((array) ($normalized['ready_to_install'] ?? [])),
            'Exclusive Hair rows' => count((array) ($normalized['exclusive_hair'] ?? [])),
            'Custom Hair rows' => count((array) ($normalized['custom_hair'] ?? [])),
            'Фото-групп в архиве' => count((array) ($photoIndex['map'] ?? [])),
        ];

        return [
            'mode' => $mode,
            'summary' => $summary,
            'base_summary' => $summary,
            'messages' => [],
            'warnings' => array_values((array) ($normalized['warnings'] ?? [])),
            'errors' => array_values((array) ($normalized['errors'] ?? [])),
            'unsupported_images' => [],
            'missing_images' => [],
            'unmatched_images' => [],
            'products' => [],
            'media_stats' => [
                'created' => 0,
                'refreshed' => 0,
                'reused' => 0,
                'deleted' => 0,
            ],
        ];
    }
    private function finalize_import_report(array $report): array
    {
        $summary = isset($report['base_summary']) && is_array($report['base_summary'])
            ? $report['base_summary']
            : (isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : []);
        $actionCounts = [];

        foreach ((array) ($report['products'] ?? []) as $productReport) {
            if (! is_array($productReport)) {
                continue;
            }

            $action = (string) ($productReport['action'] ?? 'unknown');
            $actionCounts[$action] = (int) ($actionCounts[$action] ?? 0) + 1;
        }

        foreach ($actionCounts as $action => $count) {
            $summary['Действие: ' . $action] = $count;
        }

        $summary['Товары без фото'] = count((array) ($report['missing_images'] ?? []));
        $summary['Неподдержанные фото'] = count((array) ($report['unsupported_images'] ?? []));
        $summary['Лишние фото в архиве'] = count((array) ($report['unmatched_images'] ?? []));

        if (isset($report['media_stats']) && is_array($report['media_stats'])) {
            $summary['Фото: создано'] = (int) ($report['media_stats']['created'] ?? 0);
            $summary['Фото: обновлено'] = (int) ($report['media_stats']['refreshed'] ?? 0);
            $summary['Фото: переиспользовано'] = (int) ($report['media_stats']['reused'] ?? 0);
            $summary['Фото: удалено'] = (int) ($report['media_stats']['deleted'] ?? 0);
        }

        $report['summary'] = $summary;

        if (
            ($report['mode'] ?? '') === 'import'
            && ((array) ($report['errors'] ?? [])) === []
            && ! in_array('Импорт завершён.', (array) ($report['messages'] ?? []), true)
        ) {
            $report['messages'][] = 'Импорт завершён.';
        }

        return $report;
    }
    private function build_product_report_row(array $queueItem, string $action): array
    {
        $imageCount = (int) ($queueItem['image_count'] ?? count((array) ($queueItem['image_entries'] ?? [])));
        $note = $imageCount <= 0 ? 'Нет фото' : '';

        if (($queueItem['family'] ?? '') === 'keratin' && $note === '') {
            $imageSourceKey = (string) ($queueItem['image_source_key'] ?? '');

            if ($imageSourceKey !== '') {
                $note = 'Фото по SKU ' . $imageSourceKey;
            }
        }

        return [
            'family' => (string) ($queueItem['family'] ?? ''),
            'kind' => (string) ($queueItem['kind'] ?? ''),
            'title' => (string) ($queueItem['title'] ?? ''),
            'key' => (string) ($queueItem['key'] ?? ''),
            'action' => $action,
            'images' => $imageCount,
            'note' => $note,
        ];
    }
}
