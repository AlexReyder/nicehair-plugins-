<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_Sanitizer
{
    public static function family(string $family): string
    {
        $family = sanitize_key($family);

        return array_key_exists($family, NH_TKI_ImportFamilies::labels()) ? $family : NH_TKI_ImportFamilies::TOOLS;
    }
}
