<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_AdminPage
{
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
}
