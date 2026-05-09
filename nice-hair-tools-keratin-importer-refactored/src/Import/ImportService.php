<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_Importer
{

    private const MAX_SOURCE_DIMENSION = 2400;
    private const JPEG_QUALITY = 88;
    private const WEBP_QUALITY = 86;
    private const IMAGE_PIPELINE_VERSION = 2;
    private const RUN_STORAGE_DIRECTORY = 'nh-tki-import-runs';
    private const SOURCE_STORAGE_DIRECTORY = 'nh-tki-import-source';
    private const RUN_STORAGE_FILE = 'run.json';
    private const RUN_TTL = 86400;
    private const ALLOWED_IMAGE_SIZES = [
        'thumbnail',
        'woocommerce_thumbnail',
        'woocommerce_single',
        'woocommerce_gallery_thumbnail',
    ];


    use NH_TKI_SourceFileResolver;
    use NH_TKI_ImportReportTrait;
    use NH_TKI_ImportRunStorageTrait;
    use NH_TKI_TemplateBuilderTrait;
    use NH_TKI_ExportBuilderTrait;
    use NH_TKI_HeaderNormalizerTrait;
    use NH_TKI_WorkbookNormalizerTrait;
    use NH_TKI_ToolsRowParserTrait;
    use NH_TKI_ReadyToInstallRowParserTrait;
    use NH_TKI_ExclusiveHairRowParserTrait;
    use NH_TKI_CustomHairRowParserTrait;
    use NH_TKI_KeratinRowParserTrait;
    use NH_TKI_PhotoIndexBuilderTrait;
    use NH_TKI_ImageEntryResolverTrait;
    use NH_TKI_ImageProcessorTrait;
    use NH_TKI_AttachmentImporterTrait;
    use NH_TKI_AttachmentCleanupTrait;
    use NH_TKI_ProductRepositoryTrait;
    use NH_TKI_CategoryServiceTrait;
    use NH_TKI_AttributeServiceTrait;
    use NH_TKI_MetaServiceTrait;
    use NH_TKI_ToolsProductImporterTrait;
    use NH_TKI_ReadyToInstallProductImporterTrait;
    use NH_TKI_ExclusiveHairProductImporterTrait;
    use NH_TKI_CustomHairProductImporterTrait;
    use NH_TKI_KeratinProductImporterTrait;

    private function get_request_family(array $request): string
    {
        $family = isset($request['nh_tki_family'])
            ? sanitize_key((string) wp_unslash($request['nh_tki_family']))
            : 'all';

        return in_array($family, ['tools', 'keratin', 'ready_to_install', 'exclusive_hair', 'custom_hair'], true) ? $family : 'all';
    }
    private function filter_normalized_by_family(array $normalized, string $family): array
    {
        if ($family === 'tools') {
            $normalized['keratin_rows'] = [];
            $normalized['keratin_groups'] = [];
            $normalized['ready_to_install'] = [];
            $normalized['exclusive_hair'] = [];
            $normalized['custom_hair'] = [];

            if (($normalized['tools'] ?? []) === [] && ($normalized['errors'] ?? []) === []) {
                $normalized['errors'][] = 'В XLSX не найдены товары Tools для выбранной вкладки.';
            }
        } elseif ($family === 'keratin') {
            $normalized['tools'] = [];
            $normalized['ready_to_install'] = [];
            $normalized['exclusive_hair'] = [];
            $normalized['custom_hair'] = [];

            if (($normalized['keratin_rows'] ?? []) === [] && ($normalized['errors'] ?? []) === []) {
                $normalized['errors'][] = 'В XLSX не найдены товары Keratin для выбранной вкладки.';
            }
        } elseif ($family === 'ready_to_install') {
            $normalized['tools'] = [];
            $normalized['keratin_rows'] = [];
            $normalized['keratin_groups'] = [];
            $normalized['exclusive_hair'] = [];
            $normalized['custom_hair'] = [];

            if (($normalized['ready_to_install'] ?? []) === [] && ($normalized['errors'] ?? []) === []) {
                $normalized['errors'][] = 'В XLSX не найдены товары Ready to Install для выбранной вкладки.';
            }
        } elseif ($family === 'exclusive_hair') {
            $normalized['tools'] = [];
            $normalized['keratin_rows'] = [];
            $normalized['keratin_groups'] = [];
            $normalized['ready_to_install'] = [];
            $normalized['custom_hair'] = [];

            if (($normalized['exclusive_hair'] ?? []) === [] && ($normalized['errors'] ?? []) === []) {
                $normalized['errors'][] = 'В XLSX не найдены товары Exclusive Hair для выбранной вкладки.';
            }
        } elseif ($family === 'custom_hair') {
            $normalized['tools'] = [];
            $normalized['keratin_rows'] = [];
            $normalized['keratin_groups'] = [];
            $normalized['ready_to_install'] = [];
            $normalized['exclusive_hair'] = [];

            if (($normalized['custom_hair'] ?? []) === [] && ($normalized['errors'] ?? []) === []) {
                $normalized['errors'][] = 'В XLSX не найдены товары Custom Hair для выбранной вкладки.';
            }
        }

        return $normalized;
    }
    private function bootstrap_import_environment(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (! class_exists('WooCommerce')) {
            throw new RuntimeException('WooCommerce is not active.');
        }

        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive is not available on this server.');
        }
    }
    public function stage_import(array $files, array $request = []): array
    {
        $this->bootstrap_import_environment();
        $this->cleanup_expired_runs();

        if (
            (! isset($request['nh_tki_source_mode']) || sanitize_key((string) wp_unslash($request['nh_tki_source_mode'])) !== 'server')
            && ! isset($files['nh_tki_excel'], $files['nh_tki_images'])
        ) {
            throw new RuntimeException('Не найдены загруженные файлы.');
        }

        $family = $this->get_request_family($request);
        $source = $this->resolve_source_files($files, $request);
        $runId = sanitize_key('run-' . wp_generate_password(12, false, false));
        $excelPath = (string) $source['excel_path'];
        $zipPath = (string) $source['zip_path'];

        if (($source['mode'] ?? '') === 'upload') {
            $excelPath = $this->persist_uploaded_file((array) ($source['excel'] ?? []), $runId, 'source.xlsx');
            $zipPath = $this->persist_uploaded_file((array) ($source['images'] ?? []), $runId, 'images.zip');
        }
        $workbook = NH_TKI_XLSX_Reader::read($excelPath);
        $normalized = $this->filter_normalized_by_family($this->normalize_workbook($workbook), $family);
        $photoIndex = $this->build_photo_index($zipPath);
        $report = $this->build_base_report('import', $normalized, $photoIndex, $family);

        if ($normalized['errors'] !== []) {
            return [
                'report' => $report,
            ];
        }

        $matchedPhotoKeys = [];
        $queue = [];

        foreach ($normalized['tools'] as $tool) {
            $imageEntries = $this->resolve_image_entries_for_product($photoIndex, (string) $tool['sku'], '');

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $tool['sku']);
            }

            $queue[] = [
                'family' => 'tools',
                'kind' => 'simple',
                'title' => (string) $tool['title'],
                'key' => (string) $tool['sku'],
                'image_count' => count($imageEntries),
                'image_entries' => $imageEntries,
                'image_source_key' => '',
                'payload' => $tool,
            ];
        }

        foreach ($normalized['ready_to_install'] as $item) {
            $imageEntries = $this->resolve_image_entries_for_product($photoIndex, (string) $item['sku'], (string) ($item['photo_files'] ?? ''));

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $item['sku']);
            }

            $queue[] = [
                'family' => 'ready_to_install',
                'kind' => 'simple',
                'title' => (string) $item['title'],
                'key' => (string) $item['sku'],
                'image_count' => count($imageEntries),
                'image_entries' => $imageEntries,
                'image_source_key' => '',
                'payload' => $item,
            ];
        }

        foreach ($normalized['exclusive_hair'] as $item) {
            $imageEntries = $this->resolve_image_entries_for_product($photoIndex, (string) $item['sku'], (string) ($item['photo_files'] ?? ''));

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $item['sku']);
            }

            $queue[] = [
                'family' => 'exclusive_hair',
                'kind' => 'simple',
                'title' => (string) $item['title'],
                'key' => (string) $item['sku'],
                'image_count' => count($imageEntries),
                'image_entries' => $imageEntries,
                'image_source_key' => '',
                'payload' => $item,
            ];
        }

        foreach ($normalized['custom_hair'] as $item) {
            $imageEntries = $this->resolve_custom_hair_image_entries($photoIndex, $item);

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $item['sku']);
            }

            $queue[] = [
                'family' => 'custom_hair',
                'kind' => 'simple',
                'title' => (string) $item['title'],
                'key' => (string) $item['sku'],
                'image_count' => count($imageEntries),
                'image_entries' => $imageEntries,
                'image_source_key' => '',
                'payload' => $item,
            ];
        }

        foreach ($normalized['keratin_groups'] as $group) {
            $imageEntries = [];
            $imageSku = '';

            foreach ((array) $group['variations'] as $variation) {
                $variationSku = (string) ($variation['sku'] ?? '');
                $variationImageEntries = $variationSku !== ''
                    ? $this->resolve_image_entries_for_product($photoIndex, $variationSku, '')
                    : [];

                if ($variationImageEntries !== []) {
                    $imageEntries = $variationImageEntries;
                    $imageSku = $variationSku;
                    $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, $variationSku);
                    break;
                }
            }

            $queue[] = [
                'family' => 'keratin',
                'kind' => 'variable',
                'title' => (string) $group['title'],
                'key' => (string) $group['group_key'],
                'image_count' => count($imageEntries),
                'image_entries' => $imageEntries,
                'image_source_key' => $imageSku,
                'payload' => $group,
            ];
        }

        foreach ($photoIndex['map'] as $skuKey => $entries) {
            if (isset($matchedPhotoKeys[$skuKey])) {
                continue;
            }

            $report['unmatched_images'][] = [
                'key' => $skuKey,
                'files' => array_map(static fn(array $entry): string => (string) ($entry['name'] ?? ''), $entries),
            ];
        }

        $run = [
            'id' => $runId,
            'created_at' => time(),
            'updated_at' => time(),
            'done' => false,
            'cursor' => 0,
            'total' => count($queue),
            'family' => $family,
            'excel_path' => $excelPath,
            'zip_path' => $zipPath,
            'queue' => $queue,
            'report' => $report,
        ];

        if ($run['total'] <= 0) {
            return [
                'report' => $this->finalize_import_report($report),
            ];
        }

        $this->save_run($run);

        return [
            'run_id' => $runId,
            'total' => (int) $run['total'],
        ];
    }
    public function process_import_run(string $runId, int $batchSize = 1): array
    {
        $this->bootstrap_import_environment();
        $run = $this->load_run($runId);
        $queue = isset($run['queue']) && is_array($run['queue']) ? array_values($run['queue']) : [];
        $total = (int) ($run['total'] ?? count($queue));
        $cursor = max(0, (int) ($run['cursor'] ?? 0));
        $report = isset($run['report']) && is_array($run['report']) ? $run['report'] : [];
        $report['__zip_path'] = (string) ($run['zip_path'] ?? '');

        if (! empty($run['done']) || $cursor >= $total) {
            $report = $this->finalize_import_report($report);
            $run['done'] = true;
            $run['cursor'] = $total;
            $run['updated_at'] = time();
            $run['report'] = $report;
            $this->save_run($run);

            return [
                'done' => true,
                'processed' => $total,
                'total' => $total,
                'report' => $report,
            ];
        }

        $processedInBatch = 0;
        $limit = max(1, $batchSize);

        while ($cursor < $total && $processedInBatch < $limit) {
            $queueItem = $queue[$cursor] ?? null;

            if (! is_array($queueItem)) {
                $cursor++;
                $processedInBatch++;
                continue;
            }

            $action = 'error';

            try {
                if (($queueItem['family'] ?? '') === 'tools') {
                    $result = $this->import_tool_product(
                        (array) ($queueItem['payload'] ?? []),
                        (array) ($queueItem['image_entries'] ?? []),
                        $report
                    );
                    $action = (string) ($result['action'] ?? 'error');
                } elseif (($queueItem['family'] ?? '') === 'ready_to_install') {
                    $result = $this->import_ready_to_install_product(
                        (array) ($queueItem['payload'] ?? []),
                        (array) ($queueItem['image_entries'] ?? []),
                        $report
                    );
                    $action = (string) ($result['action'] ?? 'error');
                } elseif (($queueItem['family'] ?? '') === 'exclusive_hair') {
                    $result = $this->import_exclusive_hair_product(
                        (array) ($queueItem['payload'] ?? []),
                        (array) ($queueItem['image_entries'] ?? []),
                        $report
                    );
                    $action = (string) ($result['action'] ?? 'error');
                } elseif (($queueItem['family'] ?? '') === 'custom_hair') {
                    $result = $this->import_custom_hair_product(
                        (array) ($queueItem['payload'] ?? []),
                        (array) ($queueItem['image_entries'] ?? []),
                        $report
                    );
                    $action = (string) ($result['action'] ?? 'error');
                } elseif (($queueItem['family'] ?? '') === 'keratin') {
                    $result = $this->import_keratin_group(
                        (array) ($queueItem['payload'] ?? []),
                        (array) ($queueItem['image_entries'] ?? []),
                        $report
                    );
                    $action = (string) ($result['action'] ?? 'error');
                } else {
                    $report['errors'][] = sprintf('Unknown queue family for item %s.', (string) ($queueItem['key'] ?? ''));
                }
            } catch (Throwable $exception) {
                $report['errors'][] = sprintf(
                    'Import item %s failed: %s',
                    (string) ($queueItem['key'] ?? 'unknown'),
                    $exception->getMessage()
                );
            }

            $report['products'][] = $this->build_product_report_row($queueItem, $action);
            $cursor++;
            $processedInBatch++;
        }

        unset($report['__zip_path']);

        $run['cursor'] = $cursor;
        $run['done'] = $cursor >= $total;
        $run['updated_at'] = time();
        $run['report'] = $run['done'] ? $this->finalize_import_report($report) : $report;
        $this->save_run($run);

        return [
            'done' => (bool) $run['done'],
            'processed' => $cursor,
            'total' => $total,
            'report' => $run['done'] ? $run['report'] : null,
        ];
    }
    private function run_inline_import(string $mode, array $files, array $request = []): array
    {
        $this->bootstrap_import_environment();

        if (! in_array($mode, ['preview', 'import'], true)) {
            throw new RuntimeException('Unknown import mode.');
        }

        if (
            (! isset($request['nh_tki_source_mode']) || sanitize_key((string) wp_unslash($request['nh_tki_source_mode'])) !== 'server')
            && ! isset($files['nh_tki_excel'], $files['nh_tki_images'])
        ) {
            throw new RuntimeException('Не найдены загруженные файлы.');
        }

        $family = $this->get_request_family($request);
        $source = $this->resolve_source_files($files, $request);
        $workbook = NH_TKI_XLSX_Reader::read((string) $source['excel_path']);
        $normalized = $this->filter_normalized_by_family($this->normalize_workbook($workbook), $family);
        $photoIndex = $this->build_photo_index((string) $source['zip_path']);
        $report = $this->build_base_report($mode, $normalized, $photoIndex, $family);
        $report['__zip_path'] = (string) $source['zip_path'];

        if ($normalized['errors'] !== []) {
            return $report;
        }

        $matchedPhotoKeys = [];

        foreach ($normalized['tools'] as $tool) {
            $imageEntries = $this->resolve_image_entries_for_product($photoIndex, (string) $tool['sku'], '');

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $tool['sku']);
            }

            $existing = $this->find_product_by_sku($tool['sku']);
            $action = $existing instanceof WC_Product ? 'update' : 'create';

            if ($mode === 'import') {
                $result = $this->import_tool_product($tool, $imageEntries, $report);
                $action = $result['action'];
            } elseif ($imageEntries === []) {
                $report['missing_images'][] = [
                    'family' => 'tools',
                    'title' => $tool['title'],
                    'key' => $tool['sku'],
                ];
            }

            $report['products'][] = [
                'family' => 'tools',
                'kind' => 'simple',
                'title' => $tool['title'],
                'key' => $tool['sku'],
                'action' => $mode === 'preview' ? 'would_' . $action : $action,
                'images' => count($imageEntries),
                'note' => $imageEntries === [] ? 'Нет фото' : '',
            ];
        }

        foreach ($normalized['ready_to_install'] as $item) {
            $imageEntries = $this->resolve_image_entries_for_product($photoIndex, (string) $item['sku'], (string) ($item['photo_files'] ?? ''));

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $item['sku']);
            }

            $existing = $this->find_product_by_sku((string) $item['sku']);
            $action = $existing instanceof WC_Product ? 'update' : 'create';

            if ($mode === 'import') {
                $result = $this->import_ready_to_install_product($item, $imageEntries, $report);
                $action = $result['action'];
            } elseif ($imageEntries === []) {
                $report['missing_images'][] = [
                    'family' => 'ready_to_install',
                    'title' => $item['title'],
                    'key' => $item['sku'],
                ];
            }

            $report['products'][] = [
                'family' => 'ready_to_install',
                'kind' => 'simple',
                'title' => $item['title'],
                'key' => $item['sku'],
                'action' => $mode === 'preview' ? 'would_' . $action : $action,
                'images' => count($imageEntries),
                'note' => $imageEntries === [] ? 'Нет фото' : '',
            ];
        }

        foreach ($normalized['exclusive_hair'] as $item) {
            $imageEntries = $this->resolve_image_entries_for_product($photoIndex, (string) $item['sku'], (string) ($item['photo_files'] ?? ''));

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $item['sku']);
            }

            $existing = $this->find_product_by_sku((string) $item['sku']);
            $action = $existing instanceof WC_Product ? 'update' : 'create';

            if ($mode === 'import') {
                $result = $this->import_exclusive_hair_product($item, $imageEntries, $report);
                $action = $result['action'];
            } elseif ($imageEntries === []) {
                $report['missing_images'][] = [
                    'family' => 'exclusive_hair',
                    'title' => $item['title'],
                    'key' => $item['sku'],
                ];
            }

            $report['products'][] = [
                'family' => 'exclusive_hair',
                'kind' => 'simple',
                'title' => $item['title'],
                'key' => $item['sku'],
                'action' => $mode === 'preview' ? 'would_' . $action : $action,
                'images' => count($imageEntries),
                'note' => $imageEntries === [] ? 'Нет фото' : '',
            ];
        }

        foreach ($normalized['custom_hair'] as $item) {
            $imageEntries = $this->resolve_custom_hair_image_entries($photoIndex, $item);

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $item['sku']);
            }

            $existing = $this->find_product_by_sku((string) $item['sku']);
            $action = $existing instanceof WC_Product ? 'update' : 'create';

            if ($mode === 'import') {
                $result = $this->import_custom_hair_product($item, $imageEntries, $report);
                $action = $result['action'];
            } elseif ($imageEntries === []) {
                $report['missing_images'][] = [
                    'family' => 'custom_hair',
                    'title' => $item['title'],
                    'key' => $item['sku'],
                ];
            }

            $report['products'][] = [
                'family' => 'custom_hair',
                'kind' => 'simple',
                'title' => $item['title'],
                'key' => $item['sku'],
                'action' => $mode === 'preview' ? 'would_' . $action : $action,
                'images' => count($imageEntries),
                'note' => $imageEntries === [] ? 'Нет фото' : '',
            ];
        }

        foreach ($normalized['keratin_groups'] as $group) {
            $imageEntries = [];
            $imageSku = '';

            foreach ($group['variations'] as $variation) {
                $variationSku = (string) ($variation['sku'] ?? '');
                $variationImageEntries = $variationSku !== ''
                    ? $this->resolve_image_entries_for_product($photoIndex, $variationSku, '')
                    : [];

                if ($variationImageEntries !== []) {
                    $imageEntries = $variationImageEntries;
                    $imageSku = $variationSku;
                    $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, $variationSku);
                    break;
                }
            }

            $existingParentId = $this->find_product_by_group_key($group['group_key']);
            $action = $existingParentId > 0 ? 'update' : 'create';

            if ($mode === 'import') {
                $result = $this->import_keratin_group($group, $imageEntries, $report);
                $action = $result['action'];
            } elseif ($imageEntries === []) {
                $report['missing_images'][] = [
                    'family' => 'keratin',
                    'title' => $group['title'],
                    'key' => $group['group_key'],
                ];
            }

            $report['products'][] = [
                'family' => 'keratin',
                'kind' => 'variable',
                'title' => $group['title'],
                'key' => $group['group_key'],
                'action' => $mode === 'preview' ? 'would_' . $action : $action,
                'images' => count($imageEntries),
                'note' => $imageEntries === [] ? 'Нет фото' : ($imageSku !== '' ? 'Фото по SKU ' . $imageSku : ''),
            ];
        }

        foreach ($photoIndex['map'] as $skuKey => $entries) {
            if (isset($matchedPhotoKeys[$skuKey])) {
                continue;
            }

            $report['unmatched_images'][] = [
                'key' => $skuKey,
                'files' => array_map(static fn(array $entry): string => (string) $entry['name'], $entries),
            ];
        }

        return $this->finalize_import_report($report);
    }
    public function run(string $mode, array $files, array $request = []): array
    {
        return $this->run_inline_import($mode, $files, $request);

        $this->bootstrap_import_environment();

        if (! in_array($mode, ['preview', 'import'], true)) {
            throw new RuntimeException('Unknown import mode.');
        }

        if (! isset($files['nh_tki_excel'], $files['nh_tki_images'])) {
            throw new RuntimeException('Не найдены загруженные файлы.');
        }

        $excel = $this->normalize_uploaded_file($files['nh_tki_excel'], ['xlsx']);
        $images = $this->normalize_uploaded_file($files['nh_tki_images'], ['zip']);

        $workbook = NH_TKI_XLSX_Reader::read($excel['tmp_name']);
        $normalized = $this->normalize_workbook($workbook);
        $photo_index = $this->build_photo_index($images['tmp_name']);

        $report = [
            'mode' => $mode,
            'summary' => [
                'Режим' => $mode === 'preview' ? 'Preview' : 'Import',
                'Tools rows' => count($normalized['tools']),
                'Keratin rows' => count($normalized['keratin_rows']),
                'Keratin groups' => count($normalized['keratin_groups']),
                'Фото-групп в архиве' => count($photo_index['map']),
            ],
            'messages' => [],
            'warnings' => $normalized['warnings'],
            'errors' => $normalized['errors'],
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
            '__zip_path' => $images['tmp_name'],
        ];

        if ($normalized['errors'] !== []) {
            return $report;
        }

        $matchedPhotoKeys = [];

        foreach ($normalized['tools'] as $tool) {
            $imageEntries = $this->resolve_image_entries_for_product($photo_index, (string) $tool['sku'], '');

            if ($imageEntries !== []) {
                $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, (string) $tool['sku']);
            }

            $existing = $this->find_product_by_sku($tool['sku']);
            $action = $existing instanceof WC_Product ? 'update' : 'create';

            if ($mode === 'import') {
                $result = $this->import_tool_product($tool, $imageEntries, $report);
                $action = $result['action'];
            } elseif ($imageEntries === []) {
                $report['missing_images'][] = [
                    'family' => 'tools',
                    'title' => $tool['title'],
                    'key' => $tool['sku'],
                ];
            }

            $report['products'][] = [
                'family' => 'tools',
                'kind' => 'simple',
                'title' => $tool['title'],
                'key' => $tool['sku'],
                'action' => $mode === 'preview' ? 'would_' . $action : $action,
                'images' => count($imageEntries),
                'note' => $imageEntries === [] ? 'Нет фото' : '',
            ];
        }

        foreach ($normalized['keratin_groups'] as $group) {
            $imageEntries = [];
            $imageSku = '';

            foreach ($group['variations'] as $variation) {
                $variationSku = (string) ($variation['sku'] ?? '');
                $variationImageEntries = $variationSku !== ''
                    ? $this->resolve_image_entries_for_product($photo_index, $variationSku, '')
                    : [];

                if ($variationImageEntries !== []) {
                    $imageEntries = $variationImageEntries;
                    $imageSku = $variationSku;
                    $this->mark_matched_photo_entries($matchedPhotoKeys, $imageEntries, $variationSku);
                    break;
                }
            }

            $existingParentId = $this->find_product_by_group_key($group['group_key']);
            $action = $existingParentId > 0 ? 'update' : 'create';

            if ($mode === 'import') {
                $result = $this->import_keratin_group($group, $imageEntries, $report);
                $action = $result['action'];
            } elseif ($imageEntries === []) {
                $report['missing_images'][] = [
                    'family' => 'keratin',
                    'title' => $group['title'],
                    'key' => $group['group_key'],
                ];
            }

            $report['products'][] = [
                'family' => 'keratin',
                'kind' => 'variable',
                'title' => $group['title'],
                'key' => $group['group_key'],
                'action' => $mode === 'preview' ? 'would_' . $action : $action,
                'images' => count($imageEntries),
                'note' => $imageEntries === [] ? 'Нет фото' : ($imageSku !== '' ? 'Фото по SKU ' . $imageSku : ''),
            ];
        }

        foreach ($photo_index['map'] as $skuKey => $entries) {
            if (isset($matchedPhotoKeys[$skuKey])) {
                continue;
            }

            $report['unmatched_images'][] = [
                'key' => $skuKey,
                'files' => array_map(static fn(array $entry): string => (string) $entry['name'], $entries),
            ];
        }

        $actionCounts = [];

        foreach ($report['products'] as $productReport) {
            $action = (string) ($productReport['action'] ?? 'unknown');
            $actionCounts[$action] = (int) ($actionCounts[$action] ?? 0) + 1;
        }

        foreach ($actionCounts as $action => $count) {
            $report['summary']['Действие: ' . $action] = $count;
        }

        $report['summary']['Товары без фото'] = count($report['missing_images']);
        $report['summary']['Неподдержанные фото'] = count($report['unsupported_images']);
        $report['summary']['Лишние фото в архиве'] = count($report['unmatched_images']);

        if ($mode === 'import' && $report['errors'] === []) {
            $report['messages'][] = 'Импорт завершён.';
        }

        if ($mode === 'import' && isset($report['media_stats']) && is_array($report['media_stats'])) {
            $report['summary']['Фото: создано'] = (int) ($report['media_stats']['created'] ?? 0);
            $report['summary']['Фото: обновлено'] = (int) ($report['media_stats']['refreshed'] ?? 0);
            $report['summary']['Фото: переиспользовано'] = (int) ($report['media_stats']['reused'] ?? 0);
            $report['summary']['Фото: удалено'] = (int) ($report['media_stats']['deleted'] ?? 0);
        }

        return $report;
    }
}
