<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_FileSystem
{
    public static function normalize_path(string $path): string
    {
        return wp_normalize_path($path);
    }
}
