<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_ImportFamilies
{
    public const TOOLS = 'tools';
    public const KERATIN = 'keratin';
    public const READY_TO_INSTALL = 'ready_to_install';
    public const EXCLUSIVE_HAIR = 'exclusive_hair';
    public const CUSTOM_HAIR = 'custom_hair';

    public static function labels(): array
    {
        return [
            self::TOOLS => 'Tools',
            self::KERATIN => 'Keratin',
            self::READY_TO_INSTALL => 'Ready to Install',
            self::EXCLUSIVE_HAIR => 'Exclusive Hair',
            self::CUSTOM_HAIR => 'Custom Hair',
        ];
    }
}
