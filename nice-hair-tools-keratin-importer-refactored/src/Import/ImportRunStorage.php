<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ImportRunStorageTrait
{
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
}
