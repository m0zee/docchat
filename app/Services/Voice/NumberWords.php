<?php

namespace App\Services\Voice;

/**
 * English number words for text that is read aloud, so a voice says
 * "twenty" and "two point five" instead of "two zero" and "two. five".
 */
final class NumberWords
{
    private const ONES = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    /**
     * Every number in English text spelled out. Rupee amounts of a lakh or
     * more use lakh and crore, as people in Pakistan and India say them.
     */
    public static function spell(string $text): string
    {
        // Phone numbers, even written in groups: digit by digit.
        $text = preg_replace_callback('/(?<![\w.])\+?\d(?:[ -]?\d){9,}(?!\w)/u', fn (array $m): string => self::digits((string) preg_replace('/\D/', '', $m[0])), $text) ?? $text;

        // "2026-27" is a range of years.
        $text = preg_replace('/\b((?:19|20)\d\d)\s?[-–]\s?(\d\d)\b/u', '$1 to $2', $text) ?? $text;

        return preg_replace_callback(
            '/(\+)?(?<![\w.])(\d+)(?:\.(\d+))?(?![\w])(?=(\s+(?:lakh|lac|crore|thousand|million|billion))?(\s+rupees)?)/u',
            function (array $m): string {
                [$plus, $whole] = [$m[1], $m[2]];
                $fraction = $m[3] ?? '';
                $rupees = ($m[5] ?? '') !== '' && ($m[4] ?? '') === '';

                // Phone numbers, codes and leading zeros: digit by digit.
                if ($plus !== '' || strlen($whole) >= 10 || ($whole[0] === '0' && strlen($whole) > 1 && $fraction === '')) {
                    return self::digits($whole);
                }

                if ($fraction !== '') {
                    return self::integer((int) $whole).' point '.self::digits($fraction);
                }

                $number = (int) $whole;

                if (strlen($whole) === 4 && $number >= 1900 && $number <= 2099 && ! $rupees) {
                    return self::year($number);
                }

                return $rupees && $number >= 100000 ? self::southAsian($number) : self::integer($number);
            },
            $text,
        ) ?? $text;
    }

    public static function integer(int $number): string
    {
        if ($number < 0) {
            return 'minus '.self::integer(-$number);
        }

        if ($number < 1000) {
            return self::belowThousand($number);
        }

        $parts = [];

        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand'] as $size => $name) {
            if ($number >= $size) {
                $count = intdiv($number, $size);
                $parts[] = ($count < 1000 ? self::belowThousand($count) : self::integer($count)).' '.$name;
                $number %= $size;
            }
        }

        if ($number > 0) {
            $parts[] = self::belowThousand($number);
        }

        return implode(' ', $parts);
    }

    /**
     * 1,200,000 as "twelve lakh", 25,000,000 as "two crore fifty lakh".
     */
    public static function southAsian(int $number): string
    {
        $parts = [];

        if ($number >= 10_000_000) {
            $parts[] = self::integer(intdiv($number, 10_000_000)).' crore';
            $number %= 10_000_000;
        }

        if ($number >= 100_000) {
            $parts[] = self::belowThousand(intdiv($number, 100_000)).' lakh';
            $number %= 100_000;
        }

        if ($number > 0) {
            $parts[] = self::integer($number);
        }

        return implode(' ', $parts);
    }

    public static function year(int $year): string
    {
        if ($year >= 2000 && $year < 2010) {
            return self::integer($year);
        }

        $low = $year % 100;

        return self::belowThousand(intdiv($year, 100)).' '.($low === 0 ? 'hundred' : ($low < 10 ? 'oh '.self::digits((string) $low) : self::belowThousand($low)));
    }

    public static function digits(string $digits): string
    {
        return implode(' ', array_map(fn (string $digit): string => self::ONES[(int) $digit], str_split($digits)));
    }

    private static function belowThousand(int $number): string
    {
        $words = [];

        if ($number >= 100) {
            $words[] = self::ONES[intdiv($number, 100)].' hundred';
            $number %= 100;
        }

        if ($number >= 20) {
            $words[] = self::TENS[intdiv($number, 10)].($number % 10 > 0 ? '-'.self::ONES[$number % 10] : '');
        } elseif ($number > 0 || $words === []) {
            $words[] = self::ONES[$number];
        }

        return implode(' ', $words);
    }
}
