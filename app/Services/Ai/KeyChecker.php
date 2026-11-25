<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Checks a key with the provider's free "list models" call, which costs
 * no tokens, before it is saved.
 */
class KeyChecker
{
    public const OK = 'ok';

    public const REJECTED = 'rejected';

    public const UNREACHABLE = 'unreachable';

    /**
     * @return self::OK|self::REJECTED|self::UNREACHABLE
     */
    public function check(AiProvider $provider, string $key): string
    {
        $url = (string) config('docchat.own_keys.check_urls.'.$provider->value);

        try {
            $request = Http::acceptJson()->connectTimeout(5)->timeout(10);

            $response = match ($provider) {
                AiProvider::Groq => $request->withToken($key)->get($url),
                AiProvider::Gemini => $request->withHeaders(['x-goog-api-key' => $key])->get($url, ['pageSize' => 1]),
            };
        } catch (ConnectionException) {
            return self::UNREACHABLE;
        }

        if ($response->successful()) {
            return self::OK;
        }

        return in_array($response->status(), [400, 401, 403], true) ? self::REJECTED : self::UNREACHABLE;
    }
}
