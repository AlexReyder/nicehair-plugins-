<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_PhotoIndexBuilderTrait
{
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
}
