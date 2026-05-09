<?php
/**
 * Plugin Name: Nice Hair Tools & Keratin Importer
 * Description: Imports Tools, Keratin, Ready to Install, Exclusive Hair and Custom Hair products for Nice Hair from XLSX + ZIP sources.
 * Version: 0.1.0
 * Author: Nice Hair
 * Text Domain: nice-hair-tools-keratin-importer
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_Plugin
{
    public const PAGE_SLUG = 'nice-hair-tools-keratin-importer';
    public const LEGACY_PARENT_SLUG = 'admin.php';
    public const PAGE_PARENT_SLUG = 'tools.php';
    public const AJAX_ACTION = 'nh_tki_process_run';
    public const EXPORT_ACTION = 'nh_tki_export';
    public const TEMPLATE_ACTION = 'nh_tki_template';
    public const NONCE_ACTION = 'nh_tki_run';
    public const META_SOURCE_FAMILY = '_nh_tki_source_family';
    public const META_GROUP_KEY = '_nh_tki_import_group_key';
    public const META_SOURCE_SKU = '_nh_tki_source_sku';
    public const META_ASSET_KEY = '_nh_tki_asset_key';
    public const META_ASSET_CONTEXT = '_nh_tki_asset_context';
    public const META_ASSET_SOURCE_HASH = '_nh_tki_asset_source_hash';
    public const META_ASSET_PIPELINE_VERSION = '_nh_tki_asset_pipeline_version';
    public const VIDEO_FIELD_KEY = 'field_nh_product_video_url';
    public const WEIGHT_ATTRIBUTE_SLUG = 'weight';
    public const WEIGHT_TAXONOMY = 'pa_weight';
    public const TOOLS_CATEGORY = 'Tools';
    public const KERATIN_CATEGORY = 'Keratin';
    public const READY_CATEGORY = 'Ready to Install';
    public const EXCLUSIVE_CATEGORY = 'Exclusive Hair';
    public const CUSTOM_HAIR_CATEGORY = 'Custom Hair';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'maybe_redirect_legacy_admin_url']);
        add_action('admin_menu', [self::class, 'register_admin_page']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handle_process_run_ajax']);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'handle_export_request']);
        add_action('admin_post_' . self::TEMPLATE_ACTION, [self::class, 'handle_template_request']);
    }

    public static function maybe_redirect_legacy_admin_url(): void
    {
        if (! is_admin() || ! current_user_can('manage_woocommerce')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';

        if ($page !== self::PAGE_SLUG) {
            return;
        }

        global $pagenow;

        if ($pagenow !== self::LEGACY_PARENT_SLUG) {
            return;
        }

        wp_safe_redirect(admin_url(self::PAGE_PARENT_SLUG . '?page=' . self::PAGE_SLUG));
        exit;
    }

    public static function register_admin_page(): void
    {
        add_management_page(
            'Импорт товаров Nice Hair',
            'Импорт товаров Nice Hair',
            'manage_woocommerce',
            self::PAGE_SLUG,
            [self::class, 'render_admin_page']
        );
    }

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

    public static function render_admin_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Недостаточно прав для доступа к этой странице.', 'nice-hair-tools-keratin-importer'));
        }

        $report = null;
        $activeRun = null;
        $activeFamily = self::get_active_family();
        $activeFamilyLabel = self::get_family_label($activeFamily);
        $showServerMode = self::should_show_server_source_mode();
        $uploadLimit = self::get_effective_upload_limit();
        $serverFiles = [
            'directory' => '',
            'xlsx' => [],
            'zip' => [],
        ];

        if ($showServerMode) {
            try {
                $serverFiles = (new NH_TKI_Importer())->get_server_source_files();
            } catch (Throwable $exception) {
                $serverFiles['error'] = $exception->getMessage();
            }
        }

        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && empty($_POST)
            && empty($_FILES)
            && ! empty($_SERVER['CONTENT_LENGTH'])
        ) {
            $report = [
                'mode' => 'upload',
                'summary' => [],
                'messages' => [],
                'warnings' => [],
                'errors' => [
                    sprintf(
                        'Файлы не были загружены. Вероятная причина: общий размер XLSX + ZIP больше серверного лимита %s.',
                        self::format_bytes($uploadLimit)
                    ),
                ],
                'unsupported_images' => [],
                'missing_images' => [],
                'unmatched_images' => [],
                'products' => [],
            ];
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer(self::NONCE_ACTION);
            $mode = isset($_POST['nh_tki_mode']) ? sanitize_key((string) wp_unslash($_POST['nh_tki_mode'])) : 'preview';

            try {
                $importer = new NH_TKI_Importer();

                if ($mode === 'import') {
                    $staged = $importer->stage_import($_FILES, $_POST);

                    if (isset($staged['run_id']) && is_string($staged['run_id']) && $staged['run_id'] !== '') {
                        $activeRun = $staged;
                    } else {
                        $report = $staged['report'] ?? null;
                    }
                } else {
                    $report = $importer->run($mode, $_FILES, $_POST);
                }
            } catch (Throwable $exception) {
                $report = [
                    'mode' => $mode,
                    'summary' => [],
                    'messages' => [],
                    'warnings' => [],
                    'errors' => [$exception->getMessage()],
                    'unsupported_images' => [],
                    'missing_images' => [],
                    'unmatched_images' => [],
                    'products' => [],
                ];
            }
        }

        ?>
        <div class="wrap">
            <h1>Импорт товаров Nice Hair</h1>
            <nav class="nav-tab-wrapper" aria-label="Категории импорта" style="margin-bottom: 16px;">
                <?php foreach (self::get_import_tabs() as $family => $label) : ?>
                    <a class="nav-tab <?php echo $family === $activeFamily ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::get_admin_page_url($family, $showServerMode)); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <h2><?php echo esc_html($activeFamilyLabel); ?></h2>
            <p>Загрузите Excel-файл и ZIP-архив с фотографиями прямо через браузер. После запуска импорта плагин сохранит файлы во временную папку и будет обрабатывать товары партиями, чтобы снизить риск 503 на хостинге.</p>
            <p class="description">
                Максимальный размер загрузки на этом сервере:
                <strong><?php echo esc_html(self::format_bytes($uploadLimit)); ?></strong>.
                Если сначала запускаете превью, перед импортом нужно снова выбрать XLSX и ZIP: браузер не сохраняет выбранные файлы после отправки формы.
            </p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="nh_tki_family" value="<?php echo esc_attr($activeFamily); ?>">
                <?php if (! $showServerMode) : ?>
                    <input type="hidden" name="nh_tki_source_mode" value="upload">
                <?php endif; ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <?php if ($showServerMode) : ?>
                        <tr>
                            <th scope="row">Источник файлов</th>
                            <td>
                                <fieldset>
                                    <label><input type="radio" name="nh_tki_source_mode" value="upload" checked> Загрузить файлы через браузер</label><br>
                                    <label><input type="radio" name="nh_tki_source_mode" value="server"> Использовать файлы, уже загруженные на сервер</label>
                                </fieldset>
                                <p class="description">Серверный режим скрыт от клиента и нужен только как технический fallback. Папка источников: <code><?php echo esc_html((string) ($serverFiles['directory'] ?? '')); ?></code>.</p>
                                <?php if (! empty($serverFiles['error'])) : ?>
                                    <p class="description" style="color:#b32d2e"><?php echo esc_html((string) $serverFiles['error']); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nh_tki_server_excel">XLSX на сервере</label></th>
                            <td>
                                <select id="nh_tki_server_excel" name="nh_tki_server_excel">
                                    <option value="">Выберите XLSX</option>
                                    <?php foreach ((array) ($serverFiles['xlsx'] ?? []) as $fileName) : ?>
                                        <option value="<?php echo esc_attr((string) $fileName); ?>"><?php echo esc_html((string) $fileName); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nh_tki_server_zip">ZIP на сервере</label></th>
                            <td>
                                <select id="nh_tki_server_zip" name="nh_tki_server_zip">
                                    <option value="">Выберите ZIP</option>
                                    <?php foreach ((array) ($serverFiles['zip'] ?? []) as $fileName) : ?>
                                        <option value="<?php echo esc_attr((string) $fileName); ?>"><?php echo esc_html((string) $fileName); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row"><label for="nh_tki_excel">Excel-файл</label></th>
                            <td>
                                <input type="file" id="nh_tki_excel" name="nh_tki_excel" accept=".xlsx"<?php echo $showServerMode ? '' : ' required'; ?>>
                                <p class="description">Выберите заполненный XLSX-шаблон импорта Tools / Keratin.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nh_tki_images">Архив фотографий</label></th>
                            <td>
                                <input type="file" id="nh_tki_images" name="nh_tki_images" accept=".zip"<?php echo $showServerMode ? '' : ' required'; ?>>
                                <p class="description">Выберите ZIP-архив с фотографиями. Имена файлов должны соответствовать правилам шаблона импорта.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-secondary" name="nh_tki_mode" value="preview">Показать превью</button>
                    <button type="submit" class="button button-primary" name="nh_tki_mode" value="import">Импортировать</button>
                    <a class="button" href="<?php echo esc_url(self::get_template_url($activeFamily)); ?>">Скачать шаблон</a>
                    <a class="button" href="<?php echo esc_url(self::get_export_url($activeFamily)); ?>">Экспортировать текущие товары</a>
                </p>
            </form>

            <?php if (is_array($activeRun)) : ?>
                <?php self::render_batch_runner($activeRun); ?>
            <?php endif; ?>

            <?php if (is_array($report)) : ?>
                <?php self::render_report($report); ?>
            <?php endif; ?>
        </div>
        <?php
    }

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

    public static function handle_template_request(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Недостаточно прав для скачивания шаблона.', 'nice-hair-tools-keratin-importer'));
        }

        $family = self::normalize_family(isset($_GET['nh_tki_family']) ? (string) wp_unslash($_GET['nh_tki_family']) : 'tools');
        check_admin_referer(self::TEMPLATE_ACTION . '_' . $family);

        try {
            $template = (new NH_TKI_Importer())->template_family($family);
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }

        $filename = isset($template['filename']) && is_string($template['filename'])
            ? $template['filename']
            : 'nice-hair-template.xlsx';
        $content = isset($template['content']) && is_string($template['content'])
            ? $template['content']
            : '';

        if ($content === '') {
            wp_die(esc_html__('Не удалось подготовить файл шаблона.', 'nice-hair-tools-keratin-importer'));
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

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
             data-ajax-url="<?php echo esc_url($ajaxUrl); ?>">
            <p id="nh-tki-progress-text">Подготовка batch-импорта…</p>
            <progress id="nh-tki-progress-bar" max="<?php echo esc_attr((string) $total); ?>" value="0" style="width: 100%; max-width: 640px;"></progress>
            <div id="nh-tki-report" style="margin-top: 16px;"></div>
        </div>

        <script>
        (function () {
            const root = document.getElementById('nh-tki-runner');

            if (!root) {
                return;
            }

            const progressText = document.getElementById('nh-tki-progress-text');
            const progressBar = document.getElementById('nh-tki-progress-bar');
            const reportRoot = document.getElementById('nh-tki-report');
            const runId = root.dataset.runId;
            const nonce = root.dataset.nonce;
            const ajaxUrl = root.dataset.ajaxUrl;
            let stopped = false;

            const tick = function () {
                if (stopped) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', <?php echo wp_json_encode(self::AJAX_ACTION); ?>);
                formData.append('nonce', nonce);
                formData.append('run_id', runId);
                formData.append('batch_size', '1');

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (payload) {
                        if (!payload || !payload.success) {
                            throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Batch import request failed.');
                        }

                        const data = payload.data || {};

                        if (progressText && data.progress_label) {
                            progressText.textContent = data.progress_label;
                        }

                        if (progressBar) {
                            progressBar.value = Number(data.processed || 0);
                        }

                        if (data.done) {
                            stopped = true;

                            if (progressText) {
                                progressText.textContent = 'Импорт завершён.';
                            }

                            if (reportRoot && data.report_html) {
                                reportRoot.innerHTML = data.report_html;
                            }

                            return;
                        }

                        window.setTimeout(tick, 150);
                    })
                    .catch(function (error) {
                        stopped = true;

                        if (progressText) {
                            progressText.textContent = 'Ошибка batch-импорта: ' + error.message;
                        }
                    });
            };

            tick();
        }());
        </script>
        <?php
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

    private function build_base_report(string $mode, array $normalized, array $photoIndex, string $family = 'all'): array
    {
        $summary = [
            'Р РµР¶РёРј' => $mode === 'preview' ? 'Preview' : 'Import',
            'Category' => $family === 'all' ? 'All' : ucfirst($family),
            'Tools rows' => count((array) ($normalized['tools'] ?? [])),
            'Keratin rows' => count((array) ($normalized['keratin_rows'] ?? [])),
            'Keratin groups' => count((array) ($normalized['keratin_groups'] ?? [])),
            'Ready to Install rows' => count((array) ($normalized['ready_to_install'] ?? [])),
            'Exclusive Hair rows' => count((array) ($normalized['exclusive_hair'] ?? [])),
            'Custom Hair rows' => count((array) ($normalized['custom_hair'] ?? [])),
            'Р¤РѕС‚Рѕ-РіСЂСѓРїРї РІ Р°СЂС…РёРІРµ' => count((array) ($photoIndex['map'] ?? [])),
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
            $summary['Р”РµР№СЃС‚РІРёРµ: ' . $action] = $count;
        }

        $summary['РўРѕРІР°СЂС‹ Р±РµР· С„РѕС‚Рѕ'] = count((array) ($report['missing_images'] ?? []));
        $summary['РќРµРїРѕРґРґРµСЂР¶Р°РЅРЅС‹Рµ С„РѕС‚Рѕ'] = count((array) ($report['unsupported_images'] ?? []));
        $summary['Р›РёС€РЅРёРµ С„РѕС‚Рѕ РІ Р°СЂС…РёРІРµ'] = count((array) ($report['unmatched_images'] ?? []));

        if (isset($report['media_stats']) && is_array($report['media_stats'])) {
            $summary['Р¤РѕС‚Рѕ: СЃРѕР·РґР°РЅРѕ'] = (int) ($report['media_stats']['created'] ?? 0);
            $summary['Р¤РѕС‚Рѕ: РѕР±РЅРѕРІР»РµРЅРѕ'] = (int) ($report['media_stats']['refreshed'] ?? 0);
            $summary['Р¤РѕС‚Рѕ: РїРµСЂРµРёСЃРїРѕР»СЊР·РѕРІР°РЅРѕ'] = (int) ($report['media_stats']['reused'] ?? 0);
            $summary['Р¤РѕС‚Рѕ: СѓРґР°Р»РµРЅРѕ'] = (int) ($report['media_stats']['deleted'] ?? 0);
        }

        $report['summary'] = $summary;

        if (
            ($report['mode'] ?? '') === 'import'
            && ((array) ($report['errors'] ?? [])) === []
            && ! in_array('РРјРїРѕСЂС‚ Р·Р°РІРµСЂС€С‘РЅ.', (array) ($report['messages'] ?? []), true)
        ) {
            $report['messages'][] = 'РРјРїРѕСЂС‚ Р·Р°РІРµСЂС€С‘РЅ.';
        }

        return $report;
    }

    private function build_product_report_row(array $queueItem, string $action): array
    {
        $imageCount = (int) ($queueItem['image_count'] ?? count((array) ($queueItem['image_entries'] ?? [])));
        $note = $imageCount <= 0 ? 'РќРµС‚ С„РѕС‚Рѕ' : '';

        if (($queueItem['family'] ?? '') === 'keratin' && $note === '') {
            $imageSourceKey = (string) ($queueItem['image_source_key'] ?? '');

            if ($imageSourceKey !== '') {
                $note = 'Р¤РѕС‚Рѕ РїРѕ SKU ' . $imageSourceKey;
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

    private function get_runs_base_directory(): string
    {
        $uploads = wp_get_upload_dir();

        if (! empty($uploads['error'])) {
            throw new RuntimeException('Не удалось определить uploads директорию для batch-импорта.');
        }

        $baseDirectory = trailingslashit((string) $uploads['basedir']) . self::RUN_STORAGE_DIRECTORY;

        if (! is_dir($baseDirectory) && ! wp_mkdir_p($baseDirectory)) {
            throw new RuntimeException('Не удалось создать базовую директорию batch-импорта.');
        }

        return $baseDirectory;
    }

    public function get_server_source_files(): array
    {
        $directory = $this->get_server_source_directory();
        $files = [
            'directory' => $directory,
            'xlsx' => [],
            'zip' => [],
        ];

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }

            $fileName = basename($path);
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($extension === 'xlsx') {
                $files['xlsx'][] = $fileName;
            } elseif ($extension === 'zip') {
                $files['zip'][] = $fileName;
            }
        }

        sort($files['xlsx'], SORT_NATURAL | SORT_FLAG_CASE);
        sort($files['zip'], SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    private function get_server_source_directory(): string
    {
        $uploads = wp_get_upload_dir();

        if (! empty($uploads['error'])) {
            throw new RuntimeException('Не удалось определить uploads директорию для файлов импорта.');
        }

        $directory = trailingslashit((string) $uploads['basedir']) . self::SOURCE_STORAGE_DIRECTORY;

        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            throw new RuntimeException('Не удалось создать директорию для серверных файлов импорта.');
        }

        $this->protect_server_source_directory($directory);

        return $directory;
    }

    private function protect_server_source_directory(string $directory): void
    {
        $indexPath = trailingslashit($directory) . 'index.html';

        if (! file_exists($indexPath)) {
            @file_put_contents($indexPath, '');
        }

        $htaccessPath = trailingslashit($directory) . '.htaccess';

        if (! file_exists($htaccessPath)) {
            @file_put_contents($htaccessPath, "Options -Indexes\n<Files *>\nRequire all denied\n</Files>\n");
        }
    }

    private function resolve_server_source_file(string $fileName, array $allowedExtensions): string
    {
        $fileName = sanitize_file_name(wp_basename($fileName));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileName === '' || ! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Выбран некорректный серверный файл импорта.');
        }

        $path = trailingslashit($this->get_server_source_directory()) . $fileName;

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Серверный файл импорта не найден или недоступен: ' . $fileName);
        }

        return $path;
    }

    private function resolve_source_files(array $files, array $request): array
    {
        $sourceMode = isset($request['nh_tki_source_mode'])
            ? sanitize_key((string) wp_unslash($request['nh_tki_source_mode']))
            : 'upload';

        if ($sourceMode === 'server') {
            $excelName = isset($request['nh_tki_server_excel']) ? (string) wp_unslash($request['nh_tki_server_excel']) : '';
            $zipName = isset($request['nh_tki_server_zip']) ? (string) wp_unslash($request['nh_tki_server_zip']) : '';

            return [
                'mode' => 'server',
                'excel_path' => $this->resolve_server_source_file($excelName, ['xlsx']),
                'zip_path' => $this->resolve_server_source_file($zipName, ['zip']),
            ];
        }

        if (
            (! isset($request['nh_tki_source_mode']) || sanitize_key((string) wp_unslash($request['nh_tki_source_mode'])) !== 'server')
            && ! isset($files['nh_tki_excel'], $files['nh_tki_images'])
        ) {
            throw new RuntimeException('РќРµ РЅР°Р№РґРµРЅС‹ Р·Р°РіСЂСѓР¶РµРЅРЅС‹Рµ С„Р°Р№Р»С‹.');
        }

        $excel = $this->normalize_uploaded_file($files['nh_tki_excel'], ['xlsx']);
        $images = $this->normalize_uploaded_file($files['nh_tki_images'], ['zip']);

        return [
            'mode' => 'upload',
            'excel' => $excel,
            'images' => $images,
            'excel_path' => (string) $excel['tmp_name'],
            'zip_path' => (string) $images['tmp_name'],
        ];
    }

    public function export_family(string $family): array
    {
        $this->bootstrap_import_environment();
        $family = in_array($family, ['tools', 'keratin', 'ready_to_install', 'exclusive_hair', 'custom_hair'], true) ? $family : 'tools';
        $rows = match ($family) {
            'keratin' => $this->build_keratin_export_rows(),
            'ready_to_install' => $this->build_ready_to_install_export_rows(),
            'exclusive_hair' => $this->build_exclusive_hair_export_rows(),
            'custom_hair' => $this->build_custom_hair_export_rows(),
            default => $this->build_tools_export_rows(),
        };

        $filename = sprintf(
            'nice-hair-%s-export-%s.xlsx',
            $family,
            gmdate('Ymd-His')
        );

        return [
            'filename' => $filename,
            'content' => NH_TKI_XLSX_Writer::write($rows),
        ];
    }

    public function template_family(string $family): array
    {
        $this->bootstrap_import_environment();
        $family = in_array($family, ['tools', 'keratin', 'ready_to_install', 'exclusive_hair', 'custom_hair'], true) ? $family : 'tools';
        $sheets = match ($family) {
            'keratin' => $this->build_keratin_template_sheets(),
            'ready_to_install' => $this->build_ready_to_install_template_sheets(),
            'exclusive_hair' => $this->build_exclusive_hair_template_sheets(),
            'custom_hair' => $this->build_custom_hair_template_sheets(),
            default => $this->build_tools_template_sheets(),
        };

        return [
            'filename' => sprintf('nice-hair-%s-template.xlsx', $family),
            'content' => NH_TKI_XLSX_Writer::write_workbook($sheets),
        ];
    }

    private function build_tools_template_sheets(): array
    {
        return $this->build_template_sheets('Tools', [
            'Фото в ZIP ищется автоматически по артикулу товара: например TOOL-001-01.jpg, TOOL-001-02.jpg.',
            'Цены можно писать как 25 или 25$. Колонку "Цена со скидкой" можно оставить пустой.',
        ], [
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Ссылка на видео',
        ]);
    }

    private function build_keratin_template_sheets(): array
    {
        return $this->build_template_sheets('Keratin', [
            'Подкатегория: Italian Gel Keratin или Pigmented Keratin.',
            'Артикул, Вес упаковки, Цена без скидки и Цена со скидкой можно заполнять списками через запятую в одном порядке.',
            'Фото в ZIP ищется по SKU вариации: например KER-001-10G-01.jpg, KER-001-10G-02.jpg.',
        ], [
            'Подкатегория',
            'Название товара',
            'Описание товара',
            'Артикул',
            'Вес упаковки',
            'Цена без скидки',
            'Цена со скидкой',
            'Ссылка на видео',
        ]);
    }

    private function build_ready_to_install_template_sheets(): array
    {
        return $this->build_template_sheets('Ready to Install', [
            'Обязательные атрибуты: Тип наращивания, Качество волос, Цветовая группа.',
            'В наличии: yes/no. Featured: yes/no. Статус: publish или draft.',
            'Фото: перечислите имена файлов из ZIP через запятую; если пусто, будет поиск по SKU-prefix.',
        ], [
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Тип наращивания',
            'Качество волос',
            'Цветовая группа',
            'Текстура',
            'Длина',
            'В наличии',
            'Featured',
            'Ссылка на видео',
            'Фото',
            'Статус',
        ]);
    }

    private function build_exclusive_hair_template_sheets(): array
    {
        return $this->build_template_sheets('Exclusive Hair', [
            'Обязательные поля: Базовая цена лота, Вес, гр, Текстура, Цветовая группа, Длина.',
            'В наличии: yes/no. Featured: yes/no. Статус: publish или draft.',
            'Фото: перечислите имена файлов из ZIP через запятую; если пусто, будет поиск по SKU-prefix.',
        ], [
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Базовая цена лота',
            'Вес, гр',
            'Текстура',
            'Цветовая группа',
            'Длина',
            'В наличии',
            'Featured',
            'Ссылка на видео',
            'Фото',
            'Статус',
        ]);
    }

    private function build_custom_hair_template_sheets(): array
    {
        return $this->build_template_sheets('Custom Hair', [
            'Один товар = один Тип наращивания / product form, например Bulk или Genius weft.',
            'Цветовые опции необязательны: если оставить пусто, новый товар создастся без цветов, а у существующего товара цвета не изменятся.',
            'Формат цветовых опций: #1|1|dark|SKU-01.png; #18|18|light|SKU-02.png. Группы: dark, middle, light.',
            'Доступные длины/качества/текстуры можно перечислять через запятую. Если оставить пустыми, карточка покажет все доступные значения.',
        ], [
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Тип наращивания',
            'Доступные длины',
            'Доступные качества',
            'Доступные текстуры',
            'Мин. вес, гр',
            'Шаг веса, гр',
            'Вес по умолчанию, гр',
            'Цветовые опции',
            'В наличии',
            'Featured',
            'Ссылка на видео',
            'Фото',
            'Статус',
        ]);
    }

    private function build_template_sheets(string $familyLabel, array $notes, array $headers): array
    {
        return [
            [
                'name' => 'Данные',
                'rows' => [$headers],
            ],
            [
                'name' => 'Правила',
                'rows' => $this->build_template_rules_rows($familyLabel, $notes),
            ],
        ];
    }

    private function build_template_rules_rows(string $familyLabel, array $notes): array
    {
        $rows = [
            ['Шаблон импорта Nice Hair: ' . $familyLabel],
            ['Заполняйте лист "Данные". Названия колонок на листе "Данные" не менять.'],
            ['Лист "Правила" можно оставить в XLSX при загрузке: импортер найдет лист с нужными заголовками.'],
            [],
            ['Правила категории'],
        ];

        foreach ($notes as $note) {
            $rows[] = [(string) $note];
        }

        $rows[] = [];
        $rows[] = ['Общие поля'];
        $rows[] = ['Цены можно писать как 25 или 25$. Пустая цена со скидкой означает, что скидки нет.'];
        $rows[] = ['Если есть колонка "В наличии": yes или пусто = товар в наличии, no = нет в наличии.'];
        $rows[] = ['Если есть колонка "Featured": yes = отметить товар как Featured, no или пусто = не отмечать.'];
        $rows[] = ['Если есть колонка "Статус": publish = опубликовать, draft = сохранить как черновик. Пустое значение = publish.'];
        $rows[] = ['Если есть колонка "Ссылка на видео": URL сохранится в поле nh_product_video_url.'];
        $rows[] = [];
        $rows[] = ['Фотографии'];
        $rows[] = ['Загружайте фотографии одним ZIP-архивом вместе с XLSX. Подпапки допустимы, но не обязательны.'];
        $rows[] = ['Рекомендуемое имя файла: [артикул/SKU]-[номер].[jpg/png/webp], например 0001-01.jpg, 0001-02.jpg.'];
        $rows[] = ['Файл с номером -01 будет первым: он станет главным фото товара и первым фото в карточке.'];
        $rows[] = ['Номер пишите с ведущим нулем: -01, -02, -03.'];
        $rows[] = ['Для Tools, Ready to Install, Exclusive Hair и Custom Hair используйте артикул товара.'];
        $rows[] = ['Для Keratin используйте SKU вариации, например KER-001-10G-01.jpg, а не общий group key.'];
        $rows[] = ['Если в шаблоне есть колонка "Фото", можно явно перечислить файлы через запятую. Порядок в колонке будет порядком фотографий.'];
        $rows[] = ['Если колонка "Фото" пустая или ее нет, импортер ищет файлы по префиксу SKU.'];
        $rows[] = ['В ZIP можно хранить файлы в папке категории, например Tools/0001-01.jpg.'];

        return $rows;
    }

    private function build_tools_export_rows(): array
    {
        $rows = [[
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Ссылка на видео',
        ]];

        foreach ($this->get_export_product_ids('tools') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Simple) {
                continue;
            }

            $rows[] = [
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                $product->get_sku(),
                $this->format_export_price($product->get_regular_price()),
                $this->format_export_price($product->get_sale_price()),
                $this->get_product_video_url((int) $product->get_id()),
            ];
        }

        return $rows;
    }

    private function build_keratin_export_rows(): array
    {
        $rows = [[
            'Подкатегория',
            'Название товара',
            'Описание товара',
            'Артикул',
            'Вес упаковки',
            'Цена без скидки',
            'Цена со скидкой',
            'Ссылка на видео',
        ]];

        foreach ($this->get_export_product_ids('keratin') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Variable) {
                continue;
            }

            $variations = $this->get_keratin_export_variations($product);

            if ($variations === []) {
                continue;
            }

            $rows[] = [
                $this->get_keratin_child_category_name((int) $product->get_id()),
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                implode(', ', array_column($variations, 'sku')),
                implode(', ', array_column($variations, 'weight')),
                implode(', ', array_map([$this, 'format_export_price'], array_column($variations, 'regular_price'))),
                $this->format_keratin_sale_price_export($variations),
                $this->get_product_video_url((int) $product->get_id()),
            ];
        }

        return $rows;
    }

    private function build_ready_to_install_export_rows(): array
    {
        $rows = [[
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Тип наращивания',
            'Качество волос',
            'Цветовая группа',
            'Текстура',
            'Длина',
            'В наличии',
            'Featured',
            'Ссылка на видео',
            'Фото',
            'Статус',
        ]];

        foreach ($this->get_export_product_ids('ready_to_install') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Simple) {
                continue;
            }

            $rows[] = [
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                $product->get_sku(),
                $this->format_export_price($product->get_regular_price()),
                $this->format_export_price($product->get_sale_price()),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_extension_type'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_hair_quality'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_color_group'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_texture'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_length'),
                $product->is_in_stock() ? 'yes' : 'no',
                $product->get_featured() ? 'yes' : 'no',
                $this->get_product_video_url((int) $product->get_id()),
                '',
                $product->get_status(),
            ];
        }

        return $rows;
    }

    private function build_exclusive_hair_export_rows(): array
    {
        $rows = [[
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Базовая цена лота',
            'Вес, гр',
            'Текстура',
            'Цветовая группа',
            'Длина',
            'В наличии',
            'Featured',
            'Ссылка на видео',
            'Фото',
            'Статус',
        ]];

        foreach ($this->get_export_product_ids('exclusive_hair') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Simple) {
                continue;
            }

            $rows[] = [
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                $product->get_sku(),
                $this->format_export_price($product->get_regular_price()),
                $this->format_export_price($product->get_sale_price()),
                $this->format_export_number($this->get_product_numeric_meta_value((int) $product->get_id(), 'nh_base_lot_price')),
                $this->format_export_number($this->get_product_numeric_meta_value((int) $product->get_id(), 'nh_fixed_weight_grams')),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_texture'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_color_group'),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_length'),
                $product->is_in_stock() ? 'yes' : 'no',
                $product->get_featured() ? 'yes' : 'no',
                $this->get_product_video_url((int) $product->get_id()),
                '',
                $product->get_status(),
            ];
        }

        return $rows;
    }

    private function build_custom_hair_export_rows(): array
    {
        $rows = [[
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Тип наращивания',
            'Доступные длины',
            'Доступные качества',
            'Доступные текстуры',
            'Мин. вес, гр',
            'Шаг веса, гр',
            'Вес по умолчанию, гр',
            'Цветовые опции',
            'В наличии',
            'Featured',
            'Ссылка на видео',
            'Фото',
            'Статус',
        ]];

        foreach ($this->get_export_product_ids('custom_hair') as $productId) {
            $product = wc_get_product($productId);

            if (! $product instanceof WC_Product_Simple) {
                continue;
            }

            $rows[] = [
                $product->get_name(),
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                $product->get_sku(),
                $this->format_export_price($product->get_regular_price()),
                $this->format_export_price($product->get_sale_price()),
                $this->get_product_attribute_export_value((int) $product->get_id(), 'pa_extension_type'),
                $this->get_custom_hair_choice_export_value((int) $product->get_id(), 'nh_custom_hair_available_lengths', $this->get_custom_hair_length_choice_map(), false),
                $this->get_custom_hair_choice_export_value((int) $product->get_id(), 'nh_custom_hair_available_qualities', $this->get_custom_hair_quality_choice_map()),
                $this->get_custom_hair_choice_export_value((int) $product->get_id(), 'nh_custom_hair_available_textures', $this->get_custom_hair_texture_choice_map()),
                $this->format_export_number($this->get_custom_hair_numeric_meta_value((int) $product->get_id(), 'nh_custom_hair_min_weight_grams')),
                $this->format_export_number($this->get_custom_hair_numeric_meta_value((int) $product->get_id(), 'nh_custom_hair_weight_step_grams')),
                $this->format_export_number($this->get_custom_hair_numeric_meta_value((int) $product->get_id(), 'nh_custom_hair_default_weight_grams')),
                $this->format_custom_hair_color_options_export((int) $product->get_id()),
                $product->is_in_stock() ? 'yes' : 'no',
                $product->get_featured() ? 'yes' : 'no',
                $this->get_product_video_url((int) $product->get_id()),
                '',
                $product->get_status(),
            ];
        }

        return $rows;
    }

    private function get_export_product_ids(string $family): array
    {
        $category = match ($family) {
            'keratin' => $this->get_product_category_term('keratin', NH_TKI_Plugin::KERATIN_CATEGORY),
            'ready_to_install' => $this->get_product_category_term('ready-to-install', NH_TKI_Plugin::READY_CATEGORY),
            'exclusive_hair' => $this->get_product_category_term('exclusive-hair', NH_TKI_Plugin::EXCLUSIVE_CATEGORY),
            'custom_hair' => $this->get_product_category_term('custom-hair', NH_TKI_Plugin::CUSTOM_HAIR_CATEGORY),
            default => $this->get_product_category_term('tools', NH_TKI_Plugin::TOOLS_CATEGORY),
        };

        if (! $category instanceof WP_Term) {
            return [];
        }

        $taxQuery = [
            'relation' => 'AND',
            [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => [(int) $category->term_id],
                'include_children' => in_array($family, ['keratin', 'custom_hair'], true),
            ],
            [
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => [$family === 'keratin' ? 'variable' : 'simple'],
            ],
        ];

        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => $taxQuery,
            'orderby' => [
                'menu_order' => 'ASC',
                'title' => 'ASC',
                'ID' => 'ASC',
            ],
            'no_found_rows' => true,
        ]);

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    private function get_product_category_term(string $slug, string $name): ?WP_Term
    {
        $term = get_term_by('slug', $slug, 'product_cat');

        if ($term instanceof WP_Term) {
            return $term;
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'name' => $name,
            'number' => 1,
        ]);

        return is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term ? $terms[0] : null;
    }

    private function get_product_video_url(int $productId): string
    {
        if (function_exists('get_field')) {
            $value = get_field('nh_product_video_url', $productId);

            if (is_string($value)) {
                return $value;
            }
        }

        return (string) get_post_meta($productId, 'nh_product_video_url', true);
    }

    private function get_product_attribute_export_value(int $productId, string $taxonomy): string
    {
        if (! taxonomy_exists($taxonomy)) {
            return '';
        }

        $terms = wc_get_product_terms($productId, $taxonomy, ['fields' => 'names']);

        if (! is_array($terms) || $terms === []) {
            return '';
        }

        return implode(', ', array_map('strval', $terms));
    }

    private function get_custom_hair_choice_export_value(int $productId, string $fieldName, array $choiceMap, bool $normalizeKeys = true): string
    {
        $rawValue = function_exists('get_field')
            ? get_field($fieldName, $productId)
            : get_post_meta($productId, $fieldName, true);
        $values = is_array($rawValue)
            ? $rawValue
            : (is_string($rawValue) && trim($rawValue) !== '' ? [$rawValue] : []);

        if ($values === []) {
            return '';
        }

        $labels = [];

        foreach ($values as $value) {
            $key = $normalizeKeys
                ? $this->normalize_custom_hair_key((string) $value)
                : $this->normalize_custom_hair_numeric_key((string) $value);

            if ($key === '') {
                continue;
            }

            $labels[] = (string) ($choiceMap[$key] ?? $value);
        }

        return implode(', ', array_values(array_unique(array_filter($labels))));
    }

    private function format_custom_hair_color_options_export(int $productId): string
    {
        $rows = $this->get_custom_hair_color_option_rows($productId);
        $parts = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row['color_label'] ?? ''));
            $value = trim((string) ($row['color_value'] ?? ''));
            $group = trim((string) ($row['color_group'] ?? ''));
            $imageFile = $this->get_media_field_filename($row['main_image'] ?? null);

            if ($label === '' && $value === '') {
                continue;
            }

            $parts[] = implode('|', [
                $label !== '' ? $label : $value,
                $value,
                $group,
                $imageFile,
            ]);
        }

        return implode('; ', $parts);
    }

    private function get_custom_hair_color_option_rows(int $productId): array
    {
        if (function_exists('get_field')) {
            $rows = get_field('nh_custom_hair_color_options', $productId);

            if (is_array($rows)) {
                return array_values(array_filter($rows, 'is_array'));
            }
        }

        $count = (int) get_post_meta($productId, 'nh_custom_hair_color_options', true);
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $rows[] = [
                'color_label' => get_post_meta($productId, 'nh_custom_hair_color_options_' . $index . '_color_label', true),
                'color_value' => get_post_meta($productId, 'nh_custom_hair_color_options_' . $index . '_color_value', true),
                'color_group' => get_post_meta($productId, 'nh_custom_hair_color_options_' . $index . '_color_group', true),
                'main_image' => get_post_meta($productId, 'nh_custom_hair_color_options_' . $index . '_main_image', true),
            ];
        }

        return $rows;
    }

    private function get_custom_hair_numeric_meta_value(int $productId, string $fieldName): ?float
    {
        $value = function_exists('get_field')
            ? get_field($fieldName, $productId)
            : get_post_meta($productId, $fieldName, true);

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function get_media_field_filename(mixed $value): string
    {
        $attachmentId = $this->get_attachment_id_from_media_field($value);

        if ($attachmentId <= 0) {
            return '';
        }

        $file = get_attached_file($attachmentId);

        return is_string($file) && $file !== '' ? basename($file) : '';
    }

    private function get_attachment_id_from_media_field(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        if (is_array($value)) {
            foreach (['ID', 'id'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return max(0, (int) $value[$key]);
                }
            }
        }

        return 0;
    }

    private function get_custom_hair_length_choice_map(): array
    {
        if (function_exists('nice_hair_get_custom_hair_length_choice_map')) {
            $map = nice_hair_get_custom_hair_length_choice_map();

            if (is_array($map) && $map !== []) {
                return $map;
            }
        }

        return [
            '40' => '40 cm',
            '50' => '50 cm',
            '60' => '60 cm',
            '70' => '70 cm',
            '80' => '80 cm',
            '90' => '90 cm',
        ];
    }

    private function get_custom_hair_quality_choice_map(): array
    {
        if (function_exists('nice_hair_get_custom_hair_quality_choice_map')) {
            $map = nice_hair_get_custom_hair_quality_choice_map();

            if (is_array($map) && $map !== []) {
                return $map;
            }
        }

        return [
            'lux' => 'Lux',
            'premium' => 'Premium',
            'exclusive' => 'Exclusive',
        ];
    }

    private function get_custom_hair_texture_choice_map(): array
    {
        if (function_exists('nice_hair_get_custom_hair_texture_choice_map')) {
            $map = nice_hair_get_custom_hair_texture_choice_map();

            if (is_array($map) && $map !== []) {
                return $map;
            }
        }

        return [
            'soft_straight' => 'Soft straight',
            'silky_wavy' => 'Silky wavy',
            'amazing_curly' => 'Amazing curly',
        ];
    }

    private function get_custom_hair_color_group_choice_map(): array
    {
        if (function_exists('nice_hair_get_custom_hair_color_group_choice_map')) {
            $map = nice_hair_get_custom_hair_color_group_choice_map();

            if (is_array($map) && $map !== []) {
                return $map;
            }
        }

        return [
            'light' => 'Light',
            'middle' => 'Middle',
            'dark' => 'Dark',
        ];
    }

    private function get_keratin_export_variations(WC_Product_Variable $product): array
    {
        $rows = [];

        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product((int) $variationId);

            if (! $variation instanceof WC_Product_Variation || $variation->get_status() !== 'publish') {
                continue;
            }

            $weight = $variation->get_attribute(NH_TKI_Plugin::WEIGHT_TAXONOMY);

            if ($weight === '') {
                $attributes = $variation->get_attributes();
                $weight = (string) ($attributes[NH_TKI_Plugin::WEIGHT_TAXONOMY] ?? $attributes[NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG] ?? '');
            }

            $rows[] = [
                'sku' => $variation->get_sku(),
                'weight' => $this->normalize_export_weight($weight),
                'regular_price' => $variation->get_regular_price(),
                'sale_price' => $variation->get_sale_price(),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return self::weight_sort_value((string) ($left['weight'] ?? '')) <=> self::weight_sort_value((string) ($right['weight'] ?? ''));
        });

        return $rows;
    }

    private function get_keratin_child_category_name(int $productId): string
    {
        $keratin = $this->get_product_category_term('keratin', NH_TKI_Plugin::KERATIN_CATEGORY);
        $terms = wp_get_object_terms($productId, 'product_cat');

        if (! is_array($terms)) {
            return '';
        }

        foreach ($terms as $term) {
            if ($term instanceof WP_Term && $keratin instanceof WP_Term && (int) $term->parent === (int) $keratin->term_id) {
                return $term->name;
            }
        }

        foreach ($terms as $term) {
            if ($term instanceof WP_Term && (! $keratin instanceof WP_Term || (int) $term->term_id !== (int) $keratin->term_id)) {
                return $term->name;
            }
        }

        return '';
    }

    private function derive_keratin_base_sku(WC_Product_Variable $product, array $variations): string
    {
        $parentSku = $product->get_sku();

        if ($parentSku !== '') {
            return $parentSku;
        }

        $candidates = [];

        foreach ($variations as $variation) {
            $sku = (string) ($variation['sku'] ?? '');

            if ($sku === '') {
                continue;
            }

            $candidates[] = $this->strip_weight_suffix_from_sku($sku, (string) ($variation['weight'] ?? ''));
        }

        $candidates = array_values(array_filter(array_unique($candidates), static fn(string $value): bool => $value !== ''));

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        return $candidates[0] ?? '';
    }

    private function strip_weight_suffix_from_sku(string $sku, string $weight): string
    {
        $weight = strtoupper(str_replace(' ', '', $weight));

        if ($weight === '') {
            return $sku;
        }

        $tokens = array_unique([
            $weight,
            $weight . 'G',
            str_replace('G', '', $weight),
        ]);

        foreach ($tokens as $token) {
            foreach (['-', '_', ' '] as $separator) {
                $suffix = $separator . $token;

                if ($token !== '' && strtoupper(substr($sku, -strlen($suffix))) === strtoupper($suffix)) {
                    return rtrim(substr($sku, 0, -strlen($suffix)), '-_ ');
                }
            }
        }

        return $sku;
    }

    private function normalize_export_weight(string $weight): string
    {
        $weight = trim($weight);
        $weight = preg_replace('/\s+/u', '', $weight) ?? $weight;

        return preg_replace('/g$/i', '', $weight) ?? $weight;
    }

    private function format_keratin_sale_price_export(array $variations): string
    {
        $hasSale = false;

        foreach ($variations as $variation) {
            if ((string) ($variation['sale_price'] ?? '') !== '') {
                $hasSale = true;
                break;
            }
        }

        if (! $hasSale) {
            return '';
        }

        return implode(', ', array_map(function (array $variation): string {
            return $this->format_export_price((string) ($variation['sale_price'] ?? ''));
        }, $variations));
    }

    private function format_export_price(mixed $price): string
    {
        $price = is_string($price) ? trim($price) : (string) $price;

        if ($price === '') {
            return '';
        }

        $number = (float) $price;
        $formatted = floor($number) === $number
            ? number_format($number, 0, '.', '')
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');

        return $formatted . '$';
    }

    private function format_export_number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! is_numeric($value)) {
            return '';
        }

        $number = (float) $value;

        return floor($number) === $number
            ? number_format($number, 0, '.', '')
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function get_product_numeric_meta_value(int $productId, string $metaKey): ?float
    {
        $value = get_post_meta($productId, $metaKey, true);

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }

    private function get_run_directory(string $runId): string
    {
        return trailingslashit($this->get_runs_base_directory()) . sanitize_key($runId);
    }

    private function get_run_file_path(string $runId): string
    {
        return trailingslashit($this->get_run_directory($runId)) . self::RUN_STORAGE_FILE;
    }

    private function persist_uploaded_file(array $file, string $runId, string $targetName): string
    {
        $runDirectory = $this->get_run_directory($runId);

        if (! is_dir($runDirectory) && ! wp_mkdir_p($runDirectory)) {
            throw new RuntimeException('Не удалось подготовить директорию файлов импорта.');
        }

        $source = (string) ($file['tmp_name'] ?? '');
        $target = trailingslashit($runDirectory) . sanitize_file_name($targetName);

        if ($source === '') {
            throw new RuntimeException('Временный файл импорта не найден.');
        }

        $moved = is_uploaded_file($source)
            ? move_uploaded_file($source, $target)
            : @rename($source, $target);

        if (! $moved) {
            $moved = @copy($source, $target);

            if ($moved && file_exists($source)) {
                @unlink($source);
            }
        }

        if (! $moved || ! file_exists($target)) {
            throw new RuntimeException('Не удалось сохранить загруженный файл для batch-импорта.');
        }

        return $target;
    }

    private function save_run(array $run): void
    {
        $runId = isset($run['id']) ? (string) $run['id'] : '';

        if ($runId === '') {
            throw new RuntimeException('Import run id is missing.');
        }

        $runDirectory = $this->get_run_directory($runId);

        if (! is_dir($runDirectory) && ! wp_mkdir_p($runDirectory)) {
            throw new RuntimeException('Не удалось сохранить состояние batch-импорта.');
        }

        $encoded = wp_json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Не удалось сериализовать состояние batch-импорта.');
        }

        if (file_put_contents($this->get_run_file_path($runId), $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать состояние batch-импорта.');
        }
    }

    private function load_run(string $runId): array
    {
        $path = $this->get_run_file_path($runId);

        if (! file_exists($path)) {
            throw new RuntimeException('Состояние импорта не найдено. Загрузите файлы заново и перезапустите импорт.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Файл состояния batch-импорта пуст или недоступен.');
        }

        $run = json_decode($contents, true);

        if (! is_array($run)) {
            throw new RuntimeException('Не удалось прочитать состояние batch-импорта.');
        }

        return $run;
    }

    private function cleanup_expired_runs(): void
    {
        $baseDirectory = $this->get_runs_base_directory();
        $entries = glob($baseDirectory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

        if (! is_array($entries)) {
            return;
        }

        $expiration = time() - self::RUN_TTL;

        foreach ($entries as $directory) {
            if (! is_string($directory) || $directory === '') {
                continue;
            }

            $runFile = trailingslashit($directory) . self::RUN_STORAGE_FILE;
            $lastUpdated = file_exists($runFile) ? (int) @filemtime($runFile) : (int) @filemtime($directory);

            if ($lastUpdated > 0 && $lastUpdated >= $expiration) {
                continue;
            }

            $this->delete_directory($directory);
        }
    }

    private function delete_directory(string $directory): void
    {
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->delete_directory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    public function stage_import(array $files, array $request = []): array
    {
        $this->bootstrap_import_environment();
        $this->cleanup_expired_runs();

        if (
            (! isset($request['nh_tki_source_mode']) || sanitize_key((string) wp_unslash($request['nh_tki_source_mode'])) !== 'server')
            && ! isset($files['nh_tki_excel'], $files['nh_tki_images'])
        ) {
            throw new RuntimeException('РќРµ РЅР°Р№РґРµРЅС‹ Р·Р°РіСЂСѓР¶РµРЅРЅС‹Рµ С„Р°Р№Р»С‹.');
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
            throw new RuntimeException('РќРµ РЅР°Р№РґРµРЅС‹ Р·Р°РіСЂСѓР¶РµРЅРЅС‹Рµ С„Р°Р№Р»С‹.');
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
                'note' => $imageEntries === [] ? 'РќРµС‚ С„РѕС‚Рѕ' : '',
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
                'note' => $imageEntries === [] ? 'РќРµС‚ С„РѕС‚Рѕ' : ($imageSku !== '' ? 'Р¤РѕС‚Рѕ РїРѕ SKU ' . $imageSku : ''),
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

    private function normalize_uploaded_file(array $file, array $allowedExtensions): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Ошибка загрузки файла: ' . (string) ($file['name'] ?? 'unknown'));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($tmpName === '' || ! is_uploaded_file($tmpName)) {
            throw new RuntimeException('Временный файл не найден: ' . $name);
        }

        if (! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Неподдерживаемый формат файла: ' . $name);
        }

        return [
            'name' => $name,
            'tmp_name' => $tmpName,
            'extension' => $extension,
        ];
    }

    private function normalize_workbook(array $workbook): array
    {
        $result = [
            'tools' => [],
            'ready_to_install' => [],
            'exclusive_hair' => [],
            'custom_hair' => [],
            'keratin_rows' => [],
            'keratin_groups' => [],
            'warnings' => [],
            'errors' => [],
        ];

        foreach ($workbook['sheets'] as $sheet) {
            $rows = $sheet['rows'] ?? [];

            if ($rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
                continue;
            }

            $header = $this->find_sheet_header($rows);

            if (! is_array($header)) {
                continue;
            }

            $headers = (array) $header['headers'];
            $rowOffset = (int) $header['index'];
            $dataRows = array_slice($rows, $rowOffset);

            if ($this->is_ready_to_install_sheet($headers)) {
                $parsed = $this->parse_ready_to_install_rows($dataRows, $headers, $rowOffset);
                $result['ready_to_install'] = array_merge($result['ready_to_install'], $parsed['items']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
                continue;
            }

            if ($this->is_exclusive_hair_sheet($headers)) {
                $parsed = $this->parse_exclusive_hair_rows($dataRows, $headers, $rowOffset);
                $result['exclusive_hair'] = array_merge($result['exclusive_hair'], $parsed['items']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
                continue;
            }

            if ($this->is_custom_hair_sheet($headers)) {
                $parsed = $this->parse_custom_hair_rows($dataRows, $headers, $rowOffset);
                $result['custom_hair'] = array_merge($result['custom_hair'], $parsed['items']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
                continue;
            }

            if ($this->is_tools_sheet($headers)) {
                $parsed = $this->parse_tools_rows($dataRows, $headers, $rowOffset);
                $result['tools'] = array_merge($result['tools'], $parsed['items']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
                continue;
            }

            if ($this->is_keratin_sheet($headers)) {
                $parsed = $this->parse_keratin_rows($dataRows, $headers, $rowOffset);
                $result['keratin_rows'] = array_merge($result['keratin_rows'], $parsed['rows']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
            }
        }

        if ($result['tools'] === [] && $result['keratin_rows'] === [] && $result['ready_to_install'] === [] && $result['exclusive_hair'] === [] && $result['custom_hair'] === []) {
            $result['errors'][] = 'В Excel не найдены листы с данными Tools, Keratin, Ready to Install, Exclusive Hair или Custom Hair.';

            return $result;
        }

        $result['keratin_groups'] = $this->group_keratin_rows($result['keratin_rows'], $result['warnings'], $result['errors']);

        return $result;
    }

    private function find_sheet_header(array $rows): ?array
    {
        $limit = min(10, count($rows));

        for ($index = 0; $index < $limit; $index++) {
            if (! isset($rows[$index]) || ! is_array($rows[$index])) {
                continue;
            }

            $headers = array_map([$this, 'normalize_header'], $rows[$index]);

            if ($this->is_ready_to_install_sheet($headers) || $this->is_exclusive_hair_sheet($headers) || $this->is_custom_hair_sheet($headers) || $this->is_tools_sheet($headers) || $this->is_keratin_sheet($headers)) {
                return [
                    'index' => $index,
                    'headers' => $headers,
                ];
            }
        }

        return null;
    }

    private function is_ready_to_install_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && in_array('тип наращивания', $headers, true)
            && in_array('качество волос', $headers, true)
            && in_array('цветовая группа', $headers, true);
    }

    private function is_exclusive_hair_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && in_array('текстура', $headers, true)
            && in_array('цветовая группа', $headers, true)
            && in_array('длина', $headers, true)
            && (in_array('вес, гр', $headers, true) || in_array('вес гр', $headers, true) || in_array('fixed weight', $headers, true));
    }

    private function is_custom_hair_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && in_array('тип наращивания', $headers, true)
            && in_array('цветовые опции', $headers, true)
            && (in_array('мин. вес, гр', $headers, true) || in_array('мин вес, гр', $headers, true) || in_array('min weight', $headers, true));
    }

    private function is_tools_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && in_array('цена без скидки', $headers, true)
            && ! in_array('вес упаковки', $headers, true)
            && ! in_array('вес, гр', $headers, true)
            && ! in_array('подкатегория', $headers, true)
            && ! in_array('тип наращивания', $headers, true)
            && ! in_array('качество волос', $headers, true)
            && ! in_array('цветовая группа', $headers, true);
    }

    private function is_keratin_sheet(array $headers): bool
    {
        return in_array('название товара', $headers, true)
            && in_array('артикул', $headers, true)
            && (in_array('вес упаковки', $headers, true) || in_array('вес, гр', $headers, true) || in_array('подкатегория', $headers, true));
    }

    private function normalize_header(mixed $value): string
    {
        $value = is_string($value) ? $value : (string) $value;
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    private function normalize_custom_hair_key(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (function_exists('nice_hair_normalize_shop_key')) {
            return (string) nice_hair_normalize_shop_key($value);
        }

        return str_replace('-', '_', sanitize_title($value));
    }

    private function normalize_custom_hair_numeric_key(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/\d+(?:[.,]\d+)?/', $value, $matches)) {
            $number = str_replace(',', '.', (string) $matches[0]);

            if (is_numeric($number)) {
                $float = (float) $number;

                return floor($float) === $float
                    ? number_format($float, 0, '.', '')
                    : rtrim(rtrim(number_format($float, 2, '.', ''), '0'), '.');
            }
        }

        return $this->normalize_custom_hair_key($value);
    }

    private function parse_tools_rows(array $rows, array $headers, int $rowOffset = 0): array
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
                $errors[] = sprintf('Tools row %d: title or SKU is missing.', $rowNumber);
                continue;
            }

            $regularPrice = $this->parse_price($this->get_row_value($row, $map, 'цена без скидки'));
            $salePrice = $this->parse_price($this->get_row_value($row, $map, 'цена со скидкой'));

            if ($regularPrice === null) {
                $errors[] = sprintf('Tools row %d (%s): regular price is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            $items[] = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'video_url' => $this->sanitize_video_url($this->get_row_value($row, $map, 'ссылка на видео')),
            ];
        }

        return [
            'items' => $items,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function parse_ready_to_install_rows(array $rows, array $headers, int $rowOffset = 0): array
    {
        $items = [];
        $warnings = [];
        $errors = [];
        $map = array_flip($headers);
        $requiredAttributes = [
            'тип наращивания' => 'extension_type',
            'качество волос' => 'hair_quality',
            'цветовая группа' => 'color_group',
        ];

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $rowNumber = $i + 1 + $rowOffset;
            $title = $this->get_row_value($row, $map, 'название товара');
            $sku = $this->get_row_value($row, $map, 'артикул');

            if ($title === '' && $sku === '') {
                continue;
            }

            if ($title === '' || $sku === '') {
                $errors[] = sprintf('Ready to Install row %d: title or SKU is missing.', $rowNumber);
                continue;
            }

            $regularPrice = $this->parse_price($this->get_row_value($row, $map, 'цена без скидки'));
            $salePrice = $this->parse_price($this->get_row_value($row, $map, 'цена со скидкой'));

            if ($regularPrice === null) {
                $errors[] = sprintf('Ready to Install row %d (%s): regular price is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            $attributes = [
                'pa_extension_type' => $this->get_row_value($row, $map, 'тип наращивания'),
                'pa_hair_quality' => $this->get_row_value($row, $map, 'качество волос'),
                'pa_color_group' => $this->get_row_value($row, $map, 'цветовая группа'),
                'pa_texture' => $this->get_row_value($row, $map, 'текстура'),
                'pa_length' => $this->get_row_value($row, $map, 'длина'),
            ];

            foreach ($requiredAttributes as $header => $key) {
                if ($this->get_row_value($row, $map, $header) === '') {
                    $errors[] = sprintf('Ready to Install row %d (%s): required attribute %s is missing.', $rowNumber, $sku, $key);
                    continue 2;
                }
            }

            $stockValue = $this->parse_boolean_field($this->get_row_value($row, $map, 'в наличии'), true);
            $featuredValue = $this->parse_boolean_field($this->get_row_value($row, $map, 'featured'), false);
            $status = strtolower($this->get_row_value($row, $map, 'статус'));
            $status = $status === '' ? 'publish' : $status;

            if ($stockValue === null) {
                $errors[] = sprintf('Ready to Install row %d (%s): stock value must be yes or no.', $rowNumber, $sku);
                continue;
            }

            if ($featuredValue === null) {
                $errors[] = sprintf('Ready to Install row %d (%s): Featured value must be yes or no.', $rowNumber, $sku);
                continue;
            }

            if (! in_array($status, ['publish', 'draft'], true)) {
                $errors[] = sprintf('Ready to Install row %d (%s): status must be publish or draft.', $rowNumber, $sku);
                continue;
            }

            $items[] = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'attributes' => array_filter($attributes, static fn(string $value): bool => trim($value) !== ''),
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

    private function parse_exclusive_hair_rows(array $rows, array $headers, int $rowOffset = 0): array
    {
        $items = [];
        $warnings = [];
        $errors = [];
        $map = array_flip($headers);
        $requiredAttributes = [
            'текстура' => 'texture',
            'цветовая группа' => 'color_group',
            'длина' => 'length',
        ];

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $rowNumber = $i + 1 + $rowOffset;
            $title = $this->get_row_value($row, $map, 'название товара');
            $sku = $this->get_row_value($row, $map, 'артикул');

            if ($title === '' && $sku === '') {
                continue;
            }

            if ($title === '' || $sku === '') {
                $errors[] = sprintf('Exclusive Hair row %d: title or SKU is missing.', $rowNumber);
                continue;
            }

            $regularPrice = $this->parse_price($this->get_row_value($row, $map, 'цена без скидки'));
            $salePrice = $this->parse_price($this->get_row_value($row, $map, 'цена со скидкой'));

            if ($regularPrice === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): regular price is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            $baseLotRaw = $this->get_first_row_value($row, $map, ['базовая цена лота', 'base lot price']);
            $baseLotPrice = $baseLotRaw === '' ? $regularPrice : $this->parse_positive_number($baseLotRaw);

            if ($baseLotPrice === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): base lot price is invalid.', $rowNumber, $sku);
                continue;
            }

            $fixedWeightRaw = $this->get_first_row_value($row, $map, ['вес, гр', 'вес гр', 'fixed weight']);
            $fixedWeight = $this->parse_positive_number($fixedWeightRaw);

            if ($fixedWeight === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): fixed weight is missing or invalid.', $rowNumber, $sku);
                continue;
            }

            $attributes = [
                'pa_texture' => $this->get_row_value($row, $map, 'текстура'),
                'pa_color_group' => $this->get_row_value($row, $map, 'цветовая группа'),
                'pa_length' => $this->get_row_value($row, $map, 'длина'),
                'pa_weight' => $this->parse_weight_label($fixedWeight),
            ];

            foreach ($requiredAttributes as $header => $key) {
                if ($this->get_row_value($row, $map, $header) === '') {
                    $errors[] = sprintf('Exclusive Hair row %d (%s): required attribute %s is missing.', $rowNumber, $sku, $key);
                    continue 2;
                }
            }

            $stockValue = $this->parse_boolean_field($this->get_row_value($row, $map, 'в наличии'), true);
            $featuredValue = $this->parse_boolean_field($this->get_row_value($row, $map, 'featured'), false);
            $status = strtolower($this->get_row_value($row, $map, 'статус'));
            $status = $status === '' ? 'publish' : $status;

            if ($stockValue === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): stock value must be yes or no.', $rowNumber, $sku);
                continue;
            }

            if ($featuredValue === null) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): Featured value must be yes or no.', $rowNumber, $sku);
                continue;
            }

            if (! in_array($status, ['publish', 'draft'], true)) {
                $errors[] = sprintf('Exclusive Hair row %d (%s): status must be publish or draft.', $rowNumber, $sku);
                continue;
            }

            $items[] = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'base_lot_price' => $baseLotPrice,
                'fixed_weight_grams' => $fixedWeight,
                'attributes' => array_filter($attributes, static fn(string $value): bool => trim($value) !== ''),
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

    private function parse_keratin_rows(array $rows, array $headers, int $rowOffset = 0): array
    {
        $items = [];
        $warnings = [];
        $errors = [];
        $map = array_flip($headers);
        $hasLegacyLists = isset($map['вес упаковки']) && ! isset($map['вес, гр']);

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $rowNumber = $i + 1 + $rowOffset;
            $title = $this->get_row_value($row, $map, 'название товара');
            $sku = $this->get_row_value($row, $map, 'артикул');

            if ($title === '' && $sku === '') {
                continue;
            }

            if ($title === '') {
                $errors[] = sprintf('Keratin row %d: title is missing.', $rowNumber);
                continue;
            }

            if ($hasLegacyLists) {
                $expanded = $this->expand_legacy_keratin_row($row, $map, $rowNumber);
                $items = array_merge($items, $expanded['rows']);
                $warnings = array_merge($warnings, $expanded['warnings']);
                $errors = array_merge($errors, $expanded['errors']);
                continue;
            }

            $weight = $this->parse_weight_label($this->get_row_value($row, $map, 'вес, гр'));
            $regularPrice = $this->parse_price($this->get_row_value($row, $map, 'цена без скидки'));
            $salePrice = $this->parse_price($this->get_row_value($row, $map, 'цена со скидкой'));

            if ($sku === '' || $weight === '' || $regularPrice === null) {
                $errors[] = sprintf('Keratin row %d (%s): sku, weight or regular price is invalid.', $rowNumber, $title);
                continue;
            }

            $subcategory = $this->resolve_keratin_child_category(
                $this->get_row_value($row, $map, 'подкатегория'),
                $title
            );

            if ($subcategory === '') {
                $errors[] = sprintf('Keratin row %d (%s): unable to resolve child category.', $rowNumber, $title);
                continue;
            }

            $items[] = [
                'title' => trim($title),
                'description' => $this->get_row_value($row, $map, 'описание товара'),
                'sku' => trim($sku),
                'weight' => $weight,
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'video_url' => $this->sanitize_video_url($this->get_row_value($row, $map, 'ссылка на видео')),
                'subcategory' => $subcategory,
            ];
        }

        return [
            'rows' => $items,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function expand_legacy_keratin_row(array $row, array $map, int $rowNumber): array
    {
        $rows = [];
        $warnings = [];
        $errors = [];

        $title = $this->get_row_value($row, $map, 'название товара');
        $baseSku = trim($this->get_row_value($row, $map, 'артикул'));
        $description = $this->get_row_value($row, $map, 'описание товара');
        $videoUrl = $this->sanitize_video_url($this->get_row_value($row, $map, 'ссылка на видео'));
        $subcategory = $this->resolve_keratin_child_category(
            $this->get_row_value($row, $map, 'подкатегория'),
            $title
        );

        $weights = $this->parse_weight_list($this->get_row_value($row, $map, 'вес упаковки'));
        $regularPrices = $this->parse_price_list($this->get_row_value($row, $map, 'цена без скидки'));
        $salePrices = $this->parse_price_list($this->get_row_value($row, $map, 'цена со скидкой'), true);

        if ($title === '' || $baseSku === '') {
            $errors[] = sprintf('Keratin legacy row %d: title or base SKU is missing.', $rowNumber);
            return ['rows' => $rows, 'warnings' => $warnings, 'errors' => $errors];
        }

        if ($subcategory === '') {
            $errors[] = sprintf('Keratin legacy row %d (%s): unable to resolve child category.', $rowNumber, $title);
            return ['rows' => $rows, 'warnings' => $warnings, 'errors' => $errors];
        }

        if ($weights === [] || $regularPrices === [] || count($weights) !== count($regularPrices)) {
            $errors[] = sprintf('Keratin legacy row %d (%s): weights and prices do not match.', $rowNumber, $title);
            return ['rows' => $rows, 'warnings' => $warnings, 'errors' => $errors];
        }

        $skuList = $this->parse_text_list($baseSku);

        if (count($skuList) > 1 && count($skuList) !== count($weights)) {
            $errors[] = sprintf('Keratin legacy row %d (%s): SKU list and weights do not match.', $rowNumber, $title);
            return ['rows' => $rows, 'warnings' => $warnings, 'errors' => $errors];
        }

        foreach ($weights as $index => $weight) {
            $variationSku = isset($skuList[$index]) && $skuList[$index] !== ''
                ? $skuList[$index]
                : sprintf('%s-%s', $baseSku, strtoupper(str_replace('g', 'G', $weight)));
            $rows[] = [
                'title' => trim($title),
                'description' => $description,
                'sku' => $variationSku,
                'weight' => $weight,
                'regular_price' => $regularPrices[$index],
                'sale_price' => $salePrices[$index] ?? null,
                'video_url' => $videoUrl,
                'subcategory' => $subcategory,
            ];
        }

        $warnings[] = sprintf('Keratin legacy row %d expanded into %d variations using generated SKUs from base SKU %s.', $rowNumber, count($rows), $baseSku);

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function group_keratin_rows(array $rows, array &$warnings, array &$errors): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groupKey = $this->build_group_key('keratin', $row['subcategory'], $row['title']);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'title' => $row['title'],
                    'subcategory' => $row['subcategory'],
                    'description' => '',
                    'video_url' => '',
                    'variations' => [],
                ];
            }

            if ($groups[$groupKey]['description'] === '' && $row['description'] !== '') {
                $groups[$groupKey]['description'] = $row['description'];
            }

            if ($groups[$groupKey]['video_url'] === '' && $row['video_url'] !== '') {
                $groups[$groupKey]['video_url'] = $row['video_url'];
            }

            $groups[$groupKey]['variations'][] = [
                'sku' => $row['sku'],
                'weight' => $row['weight'],
                'regular_price' => $row['regular_price'],
                'sale_price' => $row['sale_price'],
            ];
        }

        foreach ($groups as $groupKey => &$group) {
            $seenSkus = [];
            $seenWeights = [];

            foreach ($group['variations'] as $variation) {
                if (isset($seenSkus[$variation['sku']])) {
                    $errors[] = sprintf('Keratin group %s: duplicate SKU %s.', $group['title'], $variation['sku']);
                }

                if (isset($seenWeights[$variation['weight']])) {
                    $warnings[] = sprintf('Keratin group %s: duplicate weight %s, keeping multiple rows by SKU.', $group['title'], $variation['weight']);
                }

                $seenSkus[$variation['sku']] = true;
                $seenWeights[$variation['weight']] = true;
            }

            usort($group['variations'], static function (array $left, array $right): int {
                return NH_TKI_Importer::weight_sort_value($left['weight']) <=> NH_TKI_Importer::weight_sort_value($right['weight']);
            });
        }
        unset($group);

        return array_values($groups);
    }

    private static function weight_sort_value(string $label): int
    {
        return (int) preg_replace('/\D+/', '', $label);
    }

    private function build_group_key(string $family, string $subcategory, string $title): string
    {
        return sanitize_title($family . '-' . $subcategory . '-' . $title);
    }

    private function parse_weight_list(string $value): array
    {
        $parts = preg_split('/\s*,\s*/', trim($value)) ?: [];
        $result = [];

        foreach ($parts as $part) {
            $weight = $this->parse_weight_label($part);

            if ($weight !== '') {
                $result[] = $weight;
            }
        }

        return $result;
    }

    private function parse_text_list(string $value): array
    {
        $parts = preg_split('/\s*,\s*/', trim($value)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn(string $item): bool => $item !== ''));
    }

    private function parse_weight_label(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $numeric = preg_replace('/[^0-9.]+/', '', $value) ?? '';

        if ($numeric === '' || ! is_numeric($numeric)) {
            return '';
        }

        $number = (float) $numeric;
        $formatted = floor($number) === $number
            ? number_format($number, 0, '.', '')
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');

        return $formatted . 'g';
    }

    private function parse_price_list(string $value, bool $preserveEmpty = false): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $value) ?: [];
        $result = [];

        foreach ($parts as $part) {
            $parsed = $this->parse_price($part);

            if ($parsed !== null) {
                $result[] = $parsed;
            } elseif ($preserveEmpty) {
                $result[] = null;
            }
        }

        return $result;
    }

    private function parse_price(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(['$', ' ', "\xc2\xa0"], '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.]+/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function parse_positive_number(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace([' ', "\xc2\xa0"], '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.]+/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        if ($number <= 0) {
            return null;
        }

        return number_format($number, 2, '.', '');
    }

    private function parse_boolean_field(string $value, bool $default): ?bool
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return $default;
        }

        return match ($value) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
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

    private function resolve_keratin_child_category(string $subcategory, string $title): string
    {
        $normalized = mb_strtolower(trim($subcategory));

        $map = [
            'italian gel keratin' => 'Italian Gel Keratin',
            'pigmented keratin' => 'Pigmented Keratin',
        ];

        if ($normalized !== '' && isset($map[$normalized])) {
            return $map[$normalized];
        }

        $titleNormalized = mb_strtolower(trim($title));

        if (str_contains($titleNormalized, 'italian gel keratin')) {
            return 'Italian Gel Keratin';
        }

        if (str_contains($titleNormalized, 'pigmented keratin')) {
            return 'Pigmented Keratin';
        }

        return '';
    }

    private function sanitize_video_url(string $url): string
    {
        $url = trim($url);

        return $url !== '' ? esc_url_raw($url) : '';
    }

    private function get_row_value(array $row, array $headerMap, string $header): string
    {
        if (! isset($headerMap[$header])) {
            return '';
        }

        $index = (int) $headerMap[$header];
        $value = $row[$index] ?? '';

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function get_first_row_value(array $row, array $headerMap, array $headers): string
    {
        foreach ($headers as $header) {
            $value = $this->get_row_value($row, $headerMap, (string) $header);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function build_photo_index(string $zipPath): array
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Не удалось открыть ZIP-архив с фотографиями.');
        }

        $map = [];
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if (! is_array($stat) || empty($stat['name'])) {
                continue;
            }

            $name = (string) $stat['name'];

            if (str_ends_with($name, '/')) {
                continue;
            }

            $baseName = basename($name);
            $entry = [
                'name' => $name,
                'basename' => $baseName,
            ];
            $files[strtolower(str_replace('\\', '/', $name))] = $entry;
            $files[strtolower($baseName)] = $entry;

            if (! preg_match('/^([A-Za-z0-9-]+)/', $baseName, $matches)) {
                continue;
            }

            $skuKey = trim($matches[1]);

            if ($skuKey === '') {
                continue;
            }

            $map[$skuKey][] = $entry;
        }

        foreach ($map as &$entries) {
            usort($entries, static function (array $left, array $right): int {
                return strcmp((string) $left['basename'], (string) $right['basename']);
            });
        }
        unset($entries);

        $zip->close();

        return [
            'path' => $zipPath,
            'map' => $map,
            'files' => $files,
        ];
    }

    private function resolve_image_entries_for_product(array $photoIndex, string $sku, string $photoFiles = ''): array
    {
        $photoFiles = trim($photoFiles);

        if ($photoFiles === '') {
            $exactEntries = (array) ($photoIndex['map'][$sku] ?? []);

            if ($exactEntries !== []) {
                return $exactEntries;
            }

            $skuKey = strtolower(trim($sku));
            $entries = [];
            $seen = [];

            foreach ((array) ($photoIndex['files'] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entryName = (string) ($entry['name'] ?? '');
                $baseName = (string) ($entry['basename'] ?? basename($entryName));

                if ($entryName === '' || $baseName === '') {
                    continue;
                }

                $stem = strtolower((string) pathinfo($baseName, PATHINFO_FILENAME));

                if ($skuKey === '' || ! str_starts_with($stem, $skuKey)) {
                    continue;
                }

                $nextCharacter = substr($stem, strlen($skuKey), 1);

                if ($nextCharacter !== '' && preg_match('/[a-z0-9]/i', $nextCharacter)) {
                    continue;
                }

                if (isset($seen[$entryName])) {
                    continue;
                }

                $entries[] = $entry;
                $seen[$entryName] = true;
            }

            usort($entries, static function (array $left, array $right): int {
                return strcmp((string) ($left['basename'] ?? ''), (string) ($right['basename'] ?? ''));
            });

            return $entries;
        }

        $files = (array) ($photoIndex['files'] ?? []);
        $entries = [];
        $seen = [];

        foreach ($this->parse_text_list($photoFiles) as $fileName) {
            $fileName = str_replace('\\', '/', trim($fileName));
            $keys = array_unique([
                strtolower($fileName),
                strtolower(basename($fileName)),
            ]);

            foreach ($keys as $key) {
                if (! isset($files[$key]) || ! is_array($files[$key])) {
                    continue;
                }

                $entryName = (string) ($files[$key]['name'] ?? '');

                if ($entryName === '' || isset($seen[$entryName])) {
                    continue 2;
                }

                $entries[] = $files[$key];
                $seen[$entryName] = true;
                continue 2;
            }
        }

        return $entries;
    }

    private function resolve_custom_hair_image_entries(array $photoIndex, array $item): array
    {
        $sku = (string) ($item['sku'] ?? '');
        $entries = [];
        $seen = [];
        $appendEntries = static function (array $newEntries) use (&$entries, &$seen): void {
            foreach ($newEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entryName = (string) ($entry['name'] ?? '');

                if ($entryName === '' || isset($seen[$entryName])) {
                    continue;
                }

                $entries[] = $entry;
                $seen[$entryName] = true;
            }
        };

        $appendEntries($this->resolve_image_entries_for_product($photoIndex, $sku, (string) ($item['photo_files'] ?? '')));

        foreach ((array) ($item['color_options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $photoFile = trim((string) ($option['photo_file'] ?? ''));

            if ($photoFile === '') {
                continue;
            }

            $appendEntries($this->resolve_image_entries_for_product($photoIndex, $sku, $photoFile));
        }

        if ($entries === []) {
            $appendEntries($this->resolve_image_entries_for_product($photoIndex, $sku, ''));
        }

        return $entries;
    }

    private function mark_matched_photo_entries(array &$matchedPhotoKeys, array $imageEntries, string $fallbackKey = ''): void
    {
        if ($fallbackKey !== '') {
            $matchedPhotoKeys[$fallbackKey] = true;
        }

        foreach ($imageEntries as $entry) {
            $baseName = (string) ($entry['basename'] ?? basename((string) ($entry['name'] ?? '')));

            if (preg_match('/^([A-Za-z0-9-]+)/', $baseName, $matches)) {
                $matchedPhotoKeys[trim($matches[1])] = true;
            }
        }
    }

    private function import_tool_product(array $item, array $imageEntries, array &$report): array
    {
        $existing = $this->find_product_by_sku($item['sku']);

        if ($existing instanceof WC_Product && ! $existing->is_type('simple')) {
            $report['errors'][] = sprintf('Tools SKU %s is already used by a non-simple product.', $item['sku']);

            return ['action' => 'error'];
        }

        $product = $existing instanceof WC_Product_Simple
            ? $existing
            : new WC_Product_Simple($existing instanceof WC_Product ? $existing->get_id() : 0);

        $product->set_name($item['title']);
        $product->set_sku($item['sku']);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_category_ids([$this->ensure_product_category(NH_TKI_Plugin::TOOLS_CATEGORY)]);
        $product->set_regular_price($item['regular_price']);
        $product->set_sale_price($item['sale_price'] ?? '');
        $product->set_short_description($item['description']);
        $product->set_description($item['description']);
        $productId = $product->save();

        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'tools');
        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_SKU, $item['sku']);

        $this->update_video_meta($productId, $item['video_url']);

        if ($imageEntries !== []) {
            $imageIds = $this->import_images_for_key($imageEntries, 'tools|' . $item['sku'], $productId, $item['sku'], $report);

            if ($imageIds !== []) {
                $product->set_image_id((int) $imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
                $product->save();
            }
        } else {
            $report['missing_images'][] = [
                'family' => 'tools',
                'title' => $item['title'],
                'key' => $item['sku'],
            ];
        }

        return [
            'action' => $existing instanceof WC_Product ? 'updated' : 'created',
        ];
    }

    private function import_ready_to_install_product(array $item, array $imageEntries, array &$report): array
    {
        $existing = $this->find_product_by_sku((string) $item['sku']);

        if ($existing instanceof WC_Product && ! $existing->is_type('simple')) {
            $report['errors'][] = sprintf('Ready to Install SKU %s is already used by a non-simple product.', (string) $item['sku']);

            return ['action' => 'error'];
        }

        $product = $existing instanceof WC_Product_Simple
            ? $existing
            : new WC_Product_Simple($existing instanceof WC_Product ? $existing->get_id() : 0);
        $inStock = (bool) ($item['in_stock'] ?? true);

        $product->set_name((string) $item['title']);
        $product->set_sku((string) $item['sku']);
        $product->set_status((string) ($item['status'] ?? 'publish'));
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(true);
        $product->set_sold_individually(true);
        $product->set_stock_quantity($inStock ? 1 : 0);
        $product->set_stock_status($inStock ? 'instock' : 'outofstock');
        $product->set_featured((bool) ($item['featured'] ?? false));
        $product->set_category_ids([$this->ensure_product_category(NH_TKI_Plugin::READY_CATEGORY)]);
        $product->set_regular_price((string) $item['regular_price']);
        $product->set_sale_price($item['sale_price'] ?? '');
        $product->set_short_description((string) ($item['description'] ?? ''));
        $product->set_description((string) ($item['description'] ?? ''));
        $product->set_attributes($this->build_product_taxonomy_attributes((array) ($item['attributes'] ?? [])));
        $productId = $product->save();

        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'ready_to_install');
        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_SKU, (string) $item['sku']);
        $this->update_video_meta($productId, (string) ($item['video_url'] ?? ''));
        $this->update_unique_item_meta($productId, true);

        if ($imageEntries !== []) {
            $imageIds = $this->import_images_for_key($imageEntries, 'ready_to_install|' . (string) $item['sku'], $productId, (string) $item['sku'], $report);

            if ($imageIds !== []) {
                $product->set_image_id((int) $imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
                $product->save();
            }
        } else {
            $report['missing_images'][] = [
                'family' => 'ready_to_install',
                'title' => (string) $item['title'],
                'key' => (string) $item['sku'],
            ];
        }

        wc_delete_product_transients($productId);

        return [
            'action' => $existing instanceof WC_Product ? 'updated' : 'created',
        ];
    }

    private function import_exclusive_hair_product(array $item, array $imageEntries, array &$report): array
    {
        $existing = $this->find_product_by_sku((string) $item['sku']);

        if ($existing instanceof WC_Product && ! $existing->is_type('simple')) {
            $report['errors'][] = sprintf('Exclusive Hair SKU %s is already used by a non-simple product.', (string) $item['sku']);

            return ['action' => 'error'];
        }

        $product = $existing instanceof WC_Product_Simple
            ? $existing
            : new WC_Product_Simple($existing instanceof WC_Product ? $existing->get_id() : 0);
        $inStock = (bool) ($item['in_stock'] ?? true);

        $product->set_name((string) $item['title']);
        $product->set_sku((string) $item['sku']);
        $product->set_status((string) ($item['status'] ?? 'publish'));
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(true);
        $product->set_sold_individually(true);
        $product->set_stock_quantity($inStock ? 1 : 0);
        $product->set_stock_status($inStock ? 'instock' : 'outofstock');
        $product->set_featured((bool) ($item['featured'] ?? false));
        $product->set_category_ids([$this->ensure_product_category(NH_TKI_Plugin::EXCLUSIVE_CATEGORY)]);
        $product->set_regular_price((string) $item['regular_price']);
        $product->set_sale_price($item['sale_price'] ?? '');
        $product->set_short_description((string) ($item['description'] ?? ''));
        $product->set_description((string) ($item['description'] ?? ''));
        $product->set_attributes($this->build_product_taxonomy_attributes((array) ($item['attributes'] ?? [])));
        $productId = $product->save();

        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'exclusive_hair');
        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_SKU, (string) $item['sku']);
        $this->update_video_meta($productId, (string) ($item['video_url'] ?? ''));
        $this->update_unique_item_meta($productId, true);
        $this->update_exclusive_pricing_meta(
            $productId,
            (string) ($item['base_lot_price'] ?? ''),
            (string) ($item['fixed_weight_grams'] ?? '')
        );

        if ($imageEntries !== []) {
            $imageIds = $this->import_images_for_key($imageEntries, 'exclusive_hair|' . (string) $item['sku'], $productId, (string) $item['sku'], $report);

            if ($imageIds !== []) {
                $product->set_image_id((int) $imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
                $product->save();
            }
        } else {
            $report['missing_images'][] = [
                'family' => 'exclusive_hair',
                'title' => (string) $item['title'],
                'key' => (string) $item['sku'],
            ];
        }

        wc_delete_product_transients($productId);

        return [
            'action' => $existing instanceof WC_Product ? 'updated' : 'created',
        ];
    }

    private function import_custom_hair_product(array $item, array $imageEntries, array &$report): array
    {
        $existing = $this->find_product_by_sku((string) $item['sku']);

        if ($existing instanceof WC_Product && ! $existing->is_type('simple')) {
            $report['errors'][] = sprintf('Custom Hair SKU %s is already used by a non-simple product.', (string) $item['sku']);

            return ['action' => 'error'];
        }

        $product = $existing instanceof WC_Product_Simple
            ? $existing
            : new WC_Product_Simple($existing instanceof WC_Product ? $existing->get_id() : 0);
        $inStock = (bool) ($item['in_stock'] ?? true);

        $product->set_name((string) $item['title']);
        $product->set_sku((string) $item['sku']);
        $product->set_status((string) ($item['status'] ?? 'publish'));
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(false);
        $product->set_stock_quantity(null);
        $product->set_sold_individually(true);
        $product->set_stock_status($inStock ? 'instock' : 'outofstock');
        $product->set_featured((bool) ($item['featured'] ?? false));
        $product->set_category_ids([$this->ensure_product_category(NH_TKI_Plugin::CUSTOM_HAIR_CATEGORY)]);
        $product->set_regular_price((string) $item['regular_price']);
        $product->set_sale_price($item['sale_price'] ?? '');
        $product->set_short_description((string) ($item['description'] ?? ''));
        $product->set_description((string) ($item['description'] ?? ''));
        $product->set_attributes($this->build_product_taxonomy_attributes((array) ($item['attributes'] ?? [])));
        $productId = $product->save();

        $existingColorImageMap = $this->get_existing_custom_hair_color_image_map($productId);
        $contextKey = 'custom_hair|' . (string) $item['sku'];
        $imageIds = [];
        $imageIdByFile = [];

        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'custom_hair');
        update_post_meta($productId, NH_TKI_Plugin::META_SOURCE_SKU, (string) $item['sku']);
        $this->update_video_meta($productId, (string) ($item['video_url'] ?? ''));
        $this->update_unique_item_meta($productId, false);

        if ($imageEntries !== []) {
            $imageIds = $this->import_images_for_key($imageEntries, $contextKey, $productId, (string) $item['sku'], $report);
            $imageIdByFile = $this->build_imported_image_id_map($imageEntries, $contextKey);

            if ($imageIds !== []) {
                $product->set_image_id((int) $imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
                $product->save();
            }
        } else {
            $report['missing_images'][] = [
                'family' => 'custom_hair',
                'title' => (string) $item['title'],
                'key' => (string) $item['sku'],
            ];
        }

        $this->update_custom_hair_config_meta(
            $productId,
            $item,
            $imageIdByFile,
            $imageIds,
            $existingColorImageMap
        );

        wc_delete_product_transients($productId);

        return [
            'action' => $existing instanceof WC_Product ? 'updated' : 'created',
        ];
    }

    private function import_keratin_group(array $group, array $imageEntries, array &$report): array
    {
        $parentId = $this->find_product_by_group_key($group['group_key']);
        $isUpdate = $parentId > 0;

        if ($isUpdate) {
            wp_set_object_terms($parentId, 'variable', 'product_type');
            $product = new WC_Product_Variable($parentId);
        } else {
            $product = new WC_Product_Variable();
        }

        $keratinParentId = $this->ensure_product_category(NH_TKI_Plugin::KERATIN_CATEGORY);
        $childCategoryId = $this->ensure_product_category($group['subcategory'], NH_TKI_Plugin::KERATIN_CATEGORY);

        $product->set_name($group['title']);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_category_ids([$keratinParentId, $childCategoryId]);
        $product->set_short_description($group['description']);
        $product->set_description($group['description']);

        $attributeOptions = [];

        foreach ($group['variations'] as $variation) {
            $term = $this->ensure_weight_term($variation['weight']);

            if ($term instanceof WP_Term) {
                $attributeOptions[] = (int) $term->term_id;
            }
        }

        $attributeOptions = array_values(array_unique($attributeOptions));

        $attribute = new WC_Product_Attribute();
        $attribute->set_id((int) wc_attribute_taxonomy_id_by_name(NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG));
        $attribute->set_name(NH_TKI_Plugin::WEIGHT_TAXONOMY);
        $attribute->set_options($attributeOptions);
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $product->set_attributes([$attribute]);

        if (! empty($group['variations'][0]['weight'])) {
            $product->set_default_attributes([
                NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG => $group['variations'][0]['weight'],
            ]);
        }

        $parentId = $product->save();

        update_post_meta($parentId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'keratin');
        update_post_meta($parentId, NH_TKI_Plugin::META_GROUP_KEY, $group['group_key']);

        $this->update_video_meta($parentId, $group['video_url']);

        foreach ($group['variations'] as $variation) {
            $existingVariationId = wc_get_product_id_by_sku($variation['sku']);

            if ($existingVariationId > 0) {
                $existingVariation = wc_get_product($existingVariationId);

                if (! $existingVariation instanceof WC_Product_Variation) {
                    $report['errors'][] = sprintf('Keratin variation SKU %s is already used by a non-variation product.', $variation['sku']);
                    continue;
                }

                $variationProduct = $existingVariation;
            } else {
                $variationProduct = new WC_Product_Variation();
            }

            $variationProduct->set_parent_id($parentId);
            $variationProduct->set_sku($variation['sku']);
            $variationProduct->set_status('publish');
            $variationProduct->set_manage_stock(false);
            $variationProduct->set_stock_status('instock');
            $variationProduct->set_regular_price($variation['regular_price']);
            $variationProduct->set_sale_price($variation['sale_price'] ?? '');
            $variationProduct->set_attributes([
                NH_TKI_Plugin::WEIGHT_TAXONOMY => $variation['weight'],
            ]);
            $variationId = $variationProduct->save();

            update_post_meta($variationId, NH_TKI_Plugin::META_SOURCE_FAMILY, 'keratin');
            update_post_meta($variationId, NH_TKI_Plugin::META_GROUP_KEY, $group['group_key']);
            update_post_meta($variationId, NH_TKI_Plugin::META_SOURCE_SKU, $variation['sku']);
        }

        WC_Product_Variable::sync($parentId);
        wc_delete_product_transients($parentId);

        if ($imageEntries !== []) {
            $imageIds = $this->import_images_for_key($imageEntries, 'keratin|' . $group['group_key'], $parentId, $group['group_key'], $report);

            if ($imageIds !== []) {
                $product = new WC_Product_Variable($parentId);
                $product->set_image_id((int) $imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
                $product->save();
            }
        } else {
            $report['missing_images'][] = [
                'family' => 'keratin',
                'title' => $group['title'],
                'key' => $group['group_key'],
            ];
        }

        return [
            'action' => $isUpdate ? 'updated' : 'created',
        ];
    }

    private function build_product_taxonomy_attributes(array $valuesByTaxonomy): array
    {
        $attributes = [];

        foreach ($valuesByTaxonomy as $taxonomy => $value) {
            $taxonomy = sanitize_key((string) $taxonomy);
            $value = trim((string) $value);

            if ($taxonomy === '' || $value === '') {
                continue;
            }

            $term = $this->ensure_attribute_term($taxonomy, $value, $this->get_attribute_label_for_taxonomy($taxonomy));

            if (! $term instanceof WP_Term) {
                continue;
            }

            $attributeSlug = preg_replace('/^pa_/', '', $taxonomy) ?? $taxonomy;
            $attribute = new WC_Product_Attribute();
            $attribute->set_id((int) wc_attribute_taxonomy_id_by_name($attributeSlug));
            $attribute->set_name($taxonomy);
            $attribute->set_options([(int) $term->term_id]);
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes[] = $attribute;
        }

        return $attributes;
    }

    private function ensure_attribute_term(string $taxonomy, string $label, string $attributeLabel): ?WP_Term
    {
        if (! taxonomy_exists($taxonomy)) {
            $attributeSlug = preg_replace('/^pa_/', '', $taxonomy) ?? $taxonomy;
            $attributeId = wc_attribute_taxonomy_id_by_name($attributeSlug);

            if (! $attributeId) {
                $attributeId = wc_create_attribute([
                    'name' => $attributeLabel,
                    'slug' => $attributeSlug,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => true,
                ]);

                if (is_wp_error($attributeId)) {
                    return null;
                }

                delete_transient('wc_attribute_taxonomies');
            }

            register_taxonomy($taxonomy, 'product', [
                'label' => $attributeLabel,
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ]);
        }

        $slug = sanitize_title($label);
        $term = get_term_by('slug', $slug, $taxonomy);

        if (! $term instanceof WP_Term) {
            $termId = term_exists($label, $taxonomy);

            if (is_array($termId) && isset($termId['term_id'])) {
                $term = get_term((int) $termId['term_id'], $taxonomy);
            }
        }

        if ($term instanceof WP_Term) {
            return $term;
        }

        $created = wp_insert_term($label, $taxonomy, ['slug' => $slug]);

        if (is_wp_error($created)) {
            return null;
        }

        $term = get_term((int) $created['term_id'], $taxonomy);

        return $term instanceof WP_Term ? $term : null;
    }

    private function get_attribute_label_for_taxonomy(string $taxonomy): string
    {
        return match ($taxonomy) {
            'pa_extension_type' => 'Hair extensions type',
            'pa_hair_quality' => 'Hair quality',
            'pa_color_group' => 'Color',
            'pa_texture' => 'Texture',
            'pa_length' => 'Length',
            'pa_weight' => 'Weight',
            default => ucwords(str_replace(['pa_', '_', '-'], ['', ' ', ' '], $taxonomy)),
        };
    }

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

    private function update_exclusive_pricing_meta(int $postId, string $baseLotPrice, string $fixedWeightGrams): void
    {
        $baseLotPrice = $this->parse_positive_number($baseLotPrice);
        $fixedWeightGrams = $this->parse_positive_number($fixedWeightGrams);

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

        $colorRows = [];
        $fallbackImageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds))));
        $fallbackIndex = 0;

        foreach ((array) ($item['color_options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $key = (string) ($option['key'] ?? '');
            $imageId = $this->get_custom_hair_color_option_image_id((string) ($option['photo_file'] ?? ''), $imageIdByFile);

            if ($imageId <= 0 && isset($existingColorImageMap[$key])) {
                $existingImageId = (int) $existingColorImageMap[$key];
                $imageId = get_post($existingImageId) instanceof WP_Post ? $existingImageId : 0;
            }

            if ($imageId <= 0 && isset($fallbackImageIds[$fallbackIndex])) {
                $imageId = (int) $fallbackImageIds[$fallbackIndex];
                $fallbackIndex++;
            }

            $colorRows[] = [
                'color_label' => (string) ($option['label'] ?? ''),
                'color_value' => (string) ($option['value'] ?? ''),
                'color_group' => (string) ($option['group'] ?? ''),
                'main_image' => $imageId > 0 ? $imageId : '',
            ];
        }

        $this->update_custom_hair_color_options_field($postId, $colorRows);
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

    private function read_zip_image_source(ZipArchive $zip, string $entryName, string $reportSku, array &$report): ?array
    {
        $extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        $baseName = sanitize_file_name(basename($entryName));
        $rawBytes = $zip->getFromName($entryName);

        if (! is_string($rawBytes) || $rawBytes === '') {
            $report['unsupported_images'][] = [
                'sku' => $reportSku,
                'file' => $entryName,
                'reason' => 'Empty or unreadable ZIP entry.',
            ];

            return null;
        }

        return [
            'entry_name' => $entryName,
            'extension' => $extension,
            'base_name' => $baseName,
            'raw_bytes' => $rawBytes,
            'hash' => hash('sha256', $rawBytes),
        ];
    }

    private function prepare_image_source_for_upload(array $source, string $reportSku, array &$report): ?array
    {
        $entryName = (string) ($source['entry_name'] ?? '');
        $extension = strtolower((string) ($source['extension'] ?? ''));
        $baseName = (string) ($source['base_name'] ?? '');
        $rawBytes = (string) ($source['raw_bytes'] ?? '');

        if ($entryName === '' || $baseName === '' || $rawBytes === '') {
            return null;
        }

        $targetName = $baseName;
        $targetBytes = $rawBytes;

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            // keep original bytes
        } elseif (in_array($extension, ['heic', 'heif', 'dng'], true)) {
            if (! class_exists('Imagick')) {
                $report['unsupported_images'][] = [
                    'sku' => $reportSku,
                    'file' => $entryName,
                    'reason' => 'Imagick is not available for HEIC/DNG conversion.',
                ];

                return null;
            }

            $converted = $this->convert_raw_image_to_jpg($rawBytes, $baseName);

            if (! is_array($converted)) {
                $report['unsupported_images'][] = [
                    'sku' => $reportSku,
                    'file' => $entryName,
                    'reason' => 'Failed to convert HEIC/DNG to JPG.',
                ];

                return null;
            }

            $targetName = $converted['name'];
            $targetBytes = $converted['bytes'];
        } else {
            $report['unsupported_images'][] = [
                'sku' => $reportSku,
                'file' => $entryName,
                'reason' => 'Unsupported image extension.',
            ];

            return null;
        }

        $normalized = $this->normalize_image_payload($targetBytes, $targetName);

        if (is_array($normalized)) {
            $targetName = $normalized['name'];
            $targetBytes = $normalized['bytes'];
        }

        $tmpFile = wp_tempnam($targetName);

        if (! $tmpFile) {
            $report['unsupported_images'][] = [
                'sku' => $reportSku,
                'file' => $entryName,
                'reason' => 'Unable to create temporary file.',
            ];

            return null;
        }

        file_put_contents($tmpFile, $targetBytes);

        return [
            'name' => $targetName,
            'tmp_name' => $tmpFile,
        ];
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

    private function convert_raw_image_to_jpg(string $rawBytes, string $baseName): ?array
    {
        $tmpInput = wp_tempnam($baseName);

        if (! $tmpInput) {
            return null;
        }

        file_put_contents($tmpInput, $rawBytes);

        try {
            $imagick = new Imagick();
            $imagick->readImage($tmpInput);
            $hasAlpha = $this->prepare_imagick_source_image($imagick);

            if ($hasAlpha && method_exists($imagick, 'mergeImageLayers')) {
                $imagick->setImageBackgroundColor(new ImagickPixel('white'));
                $flattened = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $flattened->setImagePage(0, 0, 0, 0);
                $imagick->clear();
                $imagick->destroy();
                $imagick = $flattened;
            }

            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(self::JPEG_QUALITY);
            $bytes = $imagick->getImageBlob();
            $name = preg_replace('/\.[^.]+$/', '.jpg', $baseName) ?? ($baseName . '.jpg');
            $imagick->clear();
            $imagick->destroy();
            @unlink($tmpInput);

            if (! is_string($bytes) || $bytes === '') {
                return null;
            }

            return [
                'name' => sanitize_file_name($name),
                'bytes' => $bytes,
            ];
        } catch (Throwable) {
            @unlink($tmpInput);

            return null;
        }
    }

    private function normalize_image_payload(string $rawBytes, string $fileName): ?array
    {
        if (! class_exists('Imagick')) {
            return [
                'name' => sanitize_file_name($fileName),
                'bytes' => $rawBytes,
            ];
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($rawBytes);
            $this->prepare_imagick_source_image($imagick);

            $width = (int) $imagick->getImageWidth();
            $height = (int) $imagick->getImageHeight();

            if (max($width, $height) > self::MAX_SOURCE_DIMENSION) {
                $this->resize_imagick_to_max_dimension($imagick, $width, $height);
            }

            switch ($extension) {
                case 'jpg':
                case 'jpeg':
                    $imagick->setImageFormat('jpeg');
                    $imagick->setImageCompressionQuality(self::JPEG_QUALITY);

                    if (defined('Imagick::INTERLACE_PLANE')) {
                        $imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                    }
                    break;

                case 'webp':
                    $imagick->setImageFormat('webp');
                    $imagick->setImageCompressionQuality(self::WEBP_QUALITY);
                    break;

                case 'png':
                    $imagick->setImageFormat('png');
                    $imagick->setOption('png:compression-level', '8');
                    break;
            }

            $bytes = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();

            if (! is_string($bytes) || $bytes === '') {
                return null;
            }

            return [
                'name' => sanitize_file_name($fileName),
                'bytes' => $bytes,
            ];
        } catch (Throwable) {
            return [
                'name' => sanitize_file_name($fileName),
                'bytes' => $rawBytes,
            ];
        }
    }

    private function prepare_imagick_source_image(Imagick $imagick): bool
    {
        if (method_exists($imagick, 'setIteratorIndex')) {
            $imagick->setIteratorIndex(0);
        }

        if (method_exists($imagick, 'autoOrientImage')) {
            $imagick->autoOrientImage();
        }

        $page = $imagick->getImagePage();
        $hasPageOffset = ! empty($page['x']) || ! empty($page['y']);
        $hasAlpha = false;

        if (method_exists($imagick, 'getImageAlphaChannel')) {
            try {
                $hasAlpha = (bool) $imagick->getImageAlphaChannel();
            } catch (Throwable) {
                $hasAlpha = false;
            }
        }

        if ($hasAlpha || $hasPageOffset) {
            try {
                $imagick->trimImage(0);
            } catch (Throwable) {
                // Keep original canvas if trim is unsupported for this source codec.
            }
        }

        $imagick->setImagePage(0, 0, 0, 0);

        return $hasAlpha;
    }

    private function resize_imagick_to_max_dimension(Imagick $imagick, int $width, int $height): void
    {
        $maxDimension = self::MAX_SOURCE_DIMENSION;
        $scale = min($maxDimension / max($width, 1), $maxDimension / max($height, 1), 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $imagick->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);
        $imagick->setImagePage(0, 0, 0, 0);
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

    private function find_product_by_sku(string $sku): ?WC_Product
    {
        if ($sku === '') {
            return null;
        }

        $productId = function_exists('wc_get_product_id_by_sku')
            ? (int) wc_get_product_id_by_sku($sku)
            : 0;

        return $productId > 0 ? wc_get_product($productId) : null;
    }

    private function find_product_by_group_key(string $groupKey): int
    {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => NH_TKI_Plugin::META_GROUP_KEY,
            'meta_value' => $groupKey,
            'no_found_rows' => true,
        ]);

        return isset($ids[0]) ? (int) $ids[0] : 0;
    }

    private function ensure_product_category(string $name, string $parentName = ''): int
    {
        $parentId = 0;

        if ($parentName !== '') {
            $parentId = $this->ensure_product_category($parentName);
        }

        $slug = sanitize_title($name);
        $existing = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'name' => $name,
            'parent' => $parentId,
            'number' => 1,
        ]);

        if (is_array($existing) && isset($existing[0]) && $existing[0] instanceof WP_Term) {
            return (int) $existing[0]->term_id;
        }

        if ($slug !== '') {
            $existingBySlug = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'slug' => $slug,
                'parent' => $parentId,
                'number' => 1,
            ]);

            if (is_array($existingBySlug) && isset($existingBySlug[0]) && $existingBySlug[0] instanceof WP_Term) {
                return (int) $existingBySlug[0]->term_id;
            }
        }

        $termExists = term_exists($name, 'product_cat', $parentId);

        if (is_array($termExists) && isset($termExists['term_id'])) {
            return (int) $termExists['term_id'];
        }

        if (is_int($termExists) && $termExists > 0) {
            return $termExists;
        }

        $created = wp_insert_term($name, 'product_cat', [
            'parent' => $parentId,
        ]);

        if (is_wp_error($created)) {
            if ($created->get_error_code() === 'term_exists') {
                $termId = (int) $created->get_error_data();

                if ($termId > 0) {
                    return $termId;
                }
            }

            throw new RuntimeException('Failed to create category: ' . $name . ' (' . $created->get_error_message() . ')');
        }

        return (int) $created['term_id'];
    }

    private function ensure_weight_term(string $label): ?WP_Term
    {
        $taxonomy = NH_TKI_Plugin::WEIGHT_TAXONOMY;

        if (! taxonomy_exists($taxonomy)) {
            $attributeId = wc_attribute_taxonomy_id_by_name(NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG);

            if (! $attributeId) {
                $attributeId = wc_create_attribute([
                    'name' => 'Weight',
                    'slug' => NH_TKI_Plugin::WEIGHT_ATTRIBUTE_SLUG,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false,
                ]);

                delete_transient('wc_attribute_taxonomies');
            }

            register_taxonomy($taxonomy, 'product', [
                'label' => 'Weight',
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ]);
        }

        $slug = sanitize_title($label);
        $term = get_term_by('slug', $slug, $taxonomy);

        if ($term instanceof WP_Term) {
            return $term;
        }

        $created = wp_insert_term($label, $taxonomy, ['slug' => $slug]);

        if (is_wp_error($created)) {
            return null;
        }

        $term = get_term((int) $created['term_id'], $taxonomy);

        return $term instanceof WP_Term ? $term : null;
    }

    public static function limit_intermediate_image_sizes(array $sizes): array
    {
        return array_intersect_key($sizes, array_flip(self::ALLOWED_IMAGE_SIZES));
    }
}

final class NH_TKI_XLSX_Reader
{
    public static function read(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть XLSX-файл.');
        }

        $sharedStrings = self::read_shared_strings($zip);
        $sheetTargets = self::read_sheet_targets($zip);
        $sheets = [];

        foreach ($sheetTargets as $sheet) {
            $sheetPath = $sheet['path'];
            $sheetXml = $zip->getFromName($sheetPath);

            if (! is_string($sheetXml) || $sheetXml === '') {
                continue;
            }

            $rows = self::read_sheet_rows($sheetXml, $sharedStrings);
            $sheets[] = [
                'name' => $sheet['name'],
                'rows' => $rows,
            ];
        }

        $zip->close();

        return ['sheets' => $sheets];
    }

    private static function read_shared_strings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $document = simplexml_load_string($xml);

        if (! $document instanceof SimpleXMLElement) {
            return [];
        }

        $document->registerXPathNamespace('main', $mainNs);
        $strings = [];

        foreach ($document->xpath('//main:si') ?: [] as $item) {
            $item->registerXPathNamespace('main', $mainNs);
            $parts = [];

            foreach ($item->xpath('./main:t') ?: [] as $textNode) {
                $parts[] = (string) $textNode;
            }

            foreach ($item->xpath('./main:r/main:t') ?: [] as $runText) {
                $parts[] = (string) $runText;
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private static function read_sheet_targets(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbookXml) || ! is_string($relsXml) || $workbookXml === '' || $relsXml === '') {
            throw new RuntimeException('XLSX workbook metadata is incomplete.');
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if (! $workbook instanceof SimpleXMLElement || ! $rels instanceof SimpleXMLElement) {
            throw new RuntimeException('Failed to parse XLSX workbook metadata.');
        }

        $relationshipNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $packageRelationshipNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $workbook->registerXPathNamespace('r', $relationshipNs);
        $workbook->registerXPathNamespace('main', $mainNs);
        $rels->registerXPathNamespace('pkg', $packageRelationshipNs);

        $relMap = [];

        foreach ($rels->xpath('//pkg:Relationship') ?: [] as $relationship) {
            $attributes = $relationship->attributes();
            $id = (string) ($attributes['Id'] ?? '');
            $target = (string) ($attributes['Target'] ?? '');

            if ($id !== '' && $target !== '') {
                $target = ltrim($target, '/');
                $relMap[$id] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }

        $targets = [];

        foreach ($workbook->xpath('//main:sheets/main:sheet') ?: [] as $sheet) {
            $attrs = $sheet->attributes($relationshipNs);
            $sheetName = (string) ($sheet['name'] ?? '');
            $relationshipId = (string) ($attrs['id'] ?? '');

            if ($sheetName === '' || $relationshipId === '' || ! isset($relMap[$relationshipId])) {
                continue;
            }

            $targets[] = [
                'name' => $sheetName,
                'path' => $relMap[$relationshipId],
            ];
        }

        return $targets;
    }

    private static function read_sheet_rows(string $xml, array $sharedStrings): array
    {
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = simplexml_load_string($xml);

        if (! $sheet instanceof SimpleXMLElement) {
            return [];
        }

        $sheet->registerXPathNamespace('main', $mainNs);
        $rows = [];

        foreach ($sheet->xpath('//main:sheetData/main:row') ?: [] as $rowNode) {
            $rowNode->registerXPathNamespace('main', $mainNs);
            $row = [];

            foreach ($rowNode->xpath('./main:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('main', $mainNs);
                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = self::column_index_from_reference($reference);
                $type = (string) ($cell['t'] ?? '');
                $value = '';

                if ($type === 's') {
                    $valueNode = $cell->xpath('./main:v');
                    $sharedIndex = isset($valueNode[0]) ? (int) $valueNode[0] : 0;
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $inlineText = $cell->xpath('./main:is/main:t');
                    $value = isset($inlineText[0]) ? (string) $inlineText[0] : '';
                } elseif ($type === 'b') {
                    $valueNode = $cell->xpath('./main:v');
                    $value = (isset($valueNode[0]) ? (string) $valueNode[0] : '0') === '1' ? '1' : '0';
                } else {
                    $valueNode = $cell->xpath('./main:v');
                    $value = isset($valueNode[0]) ? (string) $valueNode[0] : '';
                }

                $row[$columnIndex] = $value;
            }

            if ($row === []) {
                continue;
            }

            ksort($row);
            $maxIndex = max(array_keys($row));
            $normalizedRow = array_fill(0, $maxIndex + 1, '');

            foreach ($row as $columnIndex => $value) {
                $normalizedRow[(int) $columnIndex] = $value;
            }

            $rows[] = $normalizedRow;
        }

        return $rows;
    }

    private static function column_index_from_reference(string $reference): int
    {
        if ($reference === '') {
            return 0;
        }

        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }
}

final class NH_TKI_XLSX_Writer
{
    public static function write(array $rows): string
    {
        return self::write_workbook([
            [
                'name' => 'data',
                'rows' => $rows,
            ],
        ]);
    }

    public static function write_workbook(array $sheets): string
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive is not available on this server.');
        }

        $sheets = self::normalize_sheets($sheets);
        $tempDirectory = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $path = tempnam($tempDirectory, 'nh-tki-export-');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Не удалось создать временный файл экспорта.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось открыть временный XLSX-файл.');
        }

        $zip->addFromString('[Content_Types].xml', self::content_types_xml(count($sheets)));
        $zip->addFromString('_rels/.rels', self::root_relationships_xml());
        $zip->addFromString('xl/workbook.xml', self::workbook_xml(array_column($sheets, 'name')));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbook_relationships_xml(count($sheets)));

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString(
                'xl/worksheets/sheet' . ($index + 1) . '.xml',
                self::sheet_xml((array) ($sheet['rows'] ?? []))
            );
        }

        $zip->close();

        $content = file_get_contents($path);
        @unlink($path);

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('Не удалось прочитать временный XLSX-файл.');
        }

        return $content;
    }

    private static function normalize_sheets(array $sheets): array
    {
        $normalized = [];
        $usedNames = [];

        foreach (array_values($sheets) as $index => $sheet) {
            if (is_array($sheet) && array_key_exists('rows', $sheet)) {
                $name = (string) ($sheet['name'] ?? 'Sheet ' . ($index + 1));
                $rows = is_array($sheet['rows']) ? $sheet['rows'] : [];
            } else {
                $name = 'Sheet ' . ($index + 1);
                $rows = is_array($sheet) ? $sheet : [];
            }

            $normalized[] = [
                'name' => self::normalize_sheet_name($name, $usedNames),
                'rows' => $rows,
            ];
        }

        if ($normalized === []) {
            $normalized[] = [
                'name' => self::normalize_sheet_name('data', $usedNames),
                'rows' => [],
            ];
        }

        return $normalized;
    }

    private static function normalize_sheet_name(string $name, array &$usedNames): string
    {
        $name = (string) preg_replace('/[\[\]\:\*\?\/\\\\]+/u', ' ', $name);
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '') {
            $name = 'Sheet';
        }

        $name = self::truncate_text($name, 31);
        $baseName = $name;
        $counter = 2;

        while (isset($usedNames[self::sheet_name_key($name)])) {
            $suffix = ' ' . $counter;
            $name = self::truncate_text($baseName, max(1, 31 - self::text_length($suffix))) . $suffix;
            $counter++;
        }

        $usedNames[self::sheet_name_key($name)] = true;

        return $name;
    }

    private static function sheet_name_key(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private static function truncate_text(string $value, int $maxLength): string
    {
        if (self::text_length($value) <= $maxLength) {
            return $value;
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    private static function text_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function content_types_xml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $index . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml . '</Types>';
    }

    private static function root_relationships_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook_xml(array $sheetNames): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>';

        foreach (array_values($sheetNames) as $index => $sheetName) {
            $sheetId = $index + 1;
            $xml .= '<sheet name="' . esc_attr((string) $sheetName) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
        }

        return $xml . '</sheets></workbook>';
    }

    private static function workbook_relationships_xml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $xml .= '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
        }

        return $xml . '</Relationships>';
    }

    private static function sheet_xml(array $rows): string
    {
        $rowCount = max(1, count($rows));
        $columnCount = 1;

        foreach ($rows as $row) {
            $columnCount = max($columnCount, is_array($row) ? count($row) : 1);
        }

        $dimension = 'A1:' . self::cell_reference($columnCount - 1, $rowCount);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . esc_attr($dimension) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<sheetData>';

        foreach (array_values($rows) as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="' . $excelRow . '">';

            foreach (array_values((array) $row) as $columnIndex => $value) {
                $reference = self::cell_reference($columnIndex, $excelRow);
                $xml .= '<c r="' . esc_attr($reference) . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml_text((string) $value) . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private static function cell_reference(int $columnIndex, int $rowIndex): string
    {
        $column = '';
        $index = $columnIndex + 1;

        while ($index > 0) {
            $index--;
            $column = chr(65 + ($index % 26)) . $column;
            $index = intdiv($index, 26);
        }

        return $column . $rowIndex;
    }

    private static function xml_text(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}

NH_TKI_Plugin::init();
