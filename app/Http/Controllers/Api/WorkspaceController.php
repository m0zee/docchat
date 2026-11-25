<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    /**
     * The workspace and abilities of the key in use. Handy as a connection test.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);

        return new JsonResponse([
            'data' => [
                'workspace' => $key->workspace === null ? null : [
                    'id' => $key->workspace->id,
                    'name' => $key->workspace->name,
                    'slug' => $key->workspace->slug,
                ],
                'key' => [
                    'name' => $key->name,
                    'prefix' => $key->prefix,
                    'type' => $key->type->value,
                    'abilities' => $key->abilities,
                    'rate_limit_per_minute' => $key->rateLimit(),
                ],
            ],
        ]);
    }
}
