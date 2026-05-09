<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_SourceFileResolver
{
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
            throw new RuntimeException('Не найдены загруженные файлы.');
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
}
