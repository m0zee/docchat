<?php

namespace App\Http\Middleware;

use App\Enums\ApiAbility;
use App\Models\ApiKey;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $key = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);
        $ability = ApiAbility::from($ability);

        if (! $key instanceof ApiKey || ! $key->allows($ability)) {
            return ApiError::response(403, 'missing_ability', 'This API key needs the "'.$ability->value.'" ability.');
        }

        return $next($request);
    }
}
