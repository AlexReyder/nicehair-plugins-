<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_BytesFormatter
{
    public static function format(int $bytes): string
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
}
