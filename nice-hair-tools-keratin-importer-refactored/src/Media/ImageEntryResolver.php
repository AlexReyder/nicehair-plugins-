<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ImageEntryResolverTrait
{
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
}
