<?php

namespace App\Support;

use Illuminate\Support\Str;

class Markdown
{
    public static function toSafeHtml(?string $markdown): ?string
    {
        if ($markdown === null || trim($markdown) === '') {
            return null;
        }

        return Str::markdown($markdown, [
            'allow_unsafe_links' => false,
            'html_input' => 'strip',
        ]);
    }
}
