<?php

namespace App\Support;

final class FileSize
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $power = $bytes > 0
            ? min((int) floor(log($bytes, 1024)), count(self::UNITS) - 1)
            : 0;

        return number_format($bytes / (1024 ** $power), $power === 0 ? 0 : 1)
            .' '.self::UNITS[$power];
    }
}
