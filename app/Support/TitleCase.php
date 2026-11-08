<?php

namespace App\Support;

class TitleCase
{
    public static function format(string $value): string
    {
        $title = mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');

        return preg_replace_callback(
            '/\b(ap|mapeh|pe|esp)\b/iu',
            fn (array $match): string => match (mb_strtolower($match[0], 'UTF-8')) {
                'ap' => 'AP',
                'mapeh' => 'MAPEH',
                'pe' => 'PE',
                'esp' => 'EsP',
            },
            $title
        ) ?? $title;
    }
}
