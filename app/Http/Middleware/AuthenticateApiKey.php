<?php

namespace App\Http\Middleware;

use App\Enums\ApiKeyType;
use App\Models\ApiKey;
use App\Support\Api\ApiError;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates "Authorization: Bearer dc_..." (or X-Api-Key), applies the
 * key's own rate limit, and makes the key's workspace the current one.
 * The limit lives here, not in route throttling, because it needs the key.
 */
class AuthenticateApiKey
{
    public const ATTRIBUTE = 'docchat.api_key';

    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $plain = $request->bearerToken() ?? $request->header('X-Api-Key');

        if (! is_string($plain) || $plain === '') {
            return ApiError::response(401, 'unauthenticated', 'Send your API key as "Authorization: Bearer dc_live_...".');
        }

        $key = ApiKey::findByPlain($plain);

        if ($key === null) {
            return ApiError::response(401, 'invalid_key', 'This API key is not valid.');
        }

        if ($key->isRevoked()) {
            return ApiError::response(401, 'revoked_key', 'This API key was revoked.');
        }

        $allowed = $types === [] ? [ApiKeyType::Live->value] : $types;

        if (! in_array($key->type->value, $allowed, true)) {
            return ApiError::response(403, 'wrong_key_type', 'This kind of API key cannot be used here.');
        }

        $limited = $this->rateLimit($key);

        if ($limited !== null) {
            return $limited;
        }

        $request->attributes->set(self::ATTRIBUTE, $key);

        if ($key->workspace !== null) {
            $this->current->set($key->workspace);
        }

        $key->touchLastUsed();

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $key->rateLimit());
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining(self::limiterKey($key), $key->rateLimit()));

        return $response;
    }

    public static function limiterKey(ApiKey $key): string
    {
        return 'api-key:'.$key->id;
    }

    private function rateLimit(ApiKey $key): ?Response
    {
        $limiterKey = self::limiterKey($key);

        if (RateLimiter::tooManyAttempts($limiterKey, $key->rateLimit())) {
            $retryAfter = max(1, RateLimiter::availableIn($limiterKey));

            return ApiError::response(
                429,
                'rate_limited',
                'Too many requests for this API key. Try again in '.$retryAfter.' seconds.',
                ['retry_after' => $retryAfter],
                [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $key->rateLimit(),
                    'X-RateLimit-Remaining' => '0',
                ],
            );
        }

        RateLimiter::hit($limiterKey, 60);

        return null;
    }
}
