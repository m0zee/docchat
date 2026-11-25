<?php

namespace App\Services\Calls;

use Illuminate\Support\Carbon;

/**
 * Outbound phone calls only start inside DOCCHAT_CALLING_HOURS ("09:00-20:00")
 * in DOCCHAT_CALLING_TIMEZONE.
 */
final class CallingHours
{
    public static function allows(?Carbon $at = null): bool
    {
        $window = (string) config('docchat.calls.calling_hours', '');

        if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($window), $m)) {
            return true;
        }

        $local = ($at ?? now())->copy()->setTimezone((string) config('docchat.calls.timezone', 'UTC'));
        $minute = $local->hour * 60 + $local->minute;
        $start = (int) $m[1] * 60 + (int) $m[2];
        $end = (int) $m[3] * 60 + (int) $m[4];

        return $start <= $end
            ? $minute >= $start && $minute < $end
            : $minute >= $start || $minute < $end;
    }

    public static function describe(): string
    {
        return config('docchat.calls.calling_hours').' ('.config('docchat.calls.timezone').')';
    }
}
