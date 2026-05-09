<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_EnvironmentGuard
{
    public static function assert_import_ready(): void
    {
        if (! class_exists('WooCommerce')) {
            throw new RuntimeException('WooCommerce must be active before running the importer.');
        }

        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required for XLSX and ZIP processing.');
        }
    }
}
