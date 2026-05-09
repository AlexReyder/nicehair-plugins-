<?php
declare(strict_types=1);

namespace Nice_Hair_Core\Core;

final class Plugin
{
    public static function boot(): void
    {
        self::load_files();
    }

    private static function load_files(): void
    {
        $base = dirname(__DIR__, 2);

        $files = [
            $base . '/src/Seed/HomePageSeeder.php',
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
}
