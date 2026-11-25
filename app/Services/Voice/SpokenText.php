<?php

namespace App\Services\Voice;

/**
 * Turns a written answer into something to read aloud: no citation marks,
 * no Markdown, one paragraph, and not too long.
 */
final class SpokenText
{
    public static function from(string $text, ?int $maxChars = null): string
    {
        $text = preg_replace('/\s*\[\d+(?:\s*[,\-–]\s*\d+)*\]/u', '', $text) ?? $text;
        $text = preg_replace('/\*\*|__|`+/u', '', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*•]\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\((?:[^)]+)\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/https?:\/\/\S+/u', 'the link in the documents', $text) ?? $text;

        // Line breaks become sentence breaks.
        $lines = array_filter(array_map('trim', preg_split('/\R+/u', $text) ?: []), fn (string $l): bool => $l !== '');
        $text = implode(' ', array_map(
            fn (string $line): string => preg_match('/[.!?:;,]$/u', $line) ? $line : $line.'.',
            $lines,
        ));
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $text = preg_replace('/\s+([.,!?;:])/u', '$1', $text) ?? $text;

        return $maxChars === null ? $text : self::limit($text, $maxChars);
    }

    /**
     * Cut at the last sentence end before $max characters, or at a space.
     */
    public static function limit(string $text, int $max): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));

        if ($end > $max / 3) {
            return mb_substr($cut, 0, $end + 1);
        }

        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space === false ? $max : $space), ' ,;:').'.';
    }
}
