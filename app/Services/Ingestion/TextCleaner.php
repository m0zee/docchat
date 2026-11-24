<?php

namespace App\Services\Ingestion;

class TextCleaner
{
    public function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace("\0", '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
