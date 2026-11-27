<?php

namespace App\Services\Voice;

/**
 * Turns a written answer into something to read aloud: no citation marks,
 * no Markdown, one paragraph, and not too long.
 */
final class SpokenText
{
    public static function from(string $text, ?int $maxChars = null, ?string $language = null): string
    {

        $text = preg_replace('/\s*\[\d+(?:\s*[,\-–]\s*\d+)*\]/u', '', $text) ?? $text;
        $text = preg_replace('/\*\*|__|`+/u', '', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*•]\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\((?:[^)]+)\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/https?:\/\/\S+/u', 'the link in the documents', $text) ?? $text;
        $text = self::numbers($text, $language);

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
     * Amounts as a voice can read them. "₹1 16 000" was read digit group by
     * digit group, and "Rs." and "6 k" letter by letter. Grouped digits are
     * joined in every language; currency, "%" and "k" become English words
     * only when the answer is known to be English ($language "en").
     */
    public static function numbers(string $text, ?string $language = null): string
    {
        // 1,200,000 and the Indian/Pakistani 12,00,000 or 1 16 000.
        $text = preg_replace_callback('/(?<![\d.,])\d{1,3}(?:,\d{2,3})+(?!\d|[.,]\d)/u', fn (array $m): string => str_replace(',', '', $m[0]), $text) ?? $text;
        $text = preg_replace_callback('/(?<![\d.,])\d{1,3}(?: \d{2})* \d{3}(?!\d|[.,]\d)/u', fn (array $m): string => str_replace(' ', '', $m[0]), $text) ?? $text;

        if ($language !== 'en') {
            return $text;
        }

        $text = preg_replace('/(\d(?:[\d.]*\d)?)\s?[kK]\b/u', '$1 thousand', $text) ?? $text;
        $text = preg_replace('/(?:₹|\bRs\.?|\bPKR|\bINR)\s?(\d(?:[\d.]*\d)?(?:\s(?:thousand|lakh|lac|crore|million|billion)\b)?)/u', '$1 rupees', $text) ?? $text;
        $text = preg_replace('/\$\s?(\d(?:[\d.]*\d)?(?:\s(?:thousand|million|billion)\b)?)/u', '$1 dollars', $text) ?? $text;

        $text = preg_replace('/(\d)\s?%/u', '$1 percent', $text) ?? $text;

        return NumberWords::spell($text);
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
