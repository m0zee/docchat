<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-user limit on chat questions (docchat.chat.rate_limit_per_minute).
 * Livewire actions are not routes, so route throttling can't cover them.
 */
class ChatRateLimit
{
    /**
     * Count one question. Returns null when allowed, or the seconds to wait.
     */
    public function attempt(User $user): ?int
    {
        $key = $this->key($user);
        $limit = max(1, (int) config('docchat.chat.rate_limit_per_minute'));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return max(1, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function key(User $user): string
    {
        return 'chat:'.$user->id;
    }
}
