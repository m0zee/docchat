<?php

namespace App\Http\Middleware;

use App\Enums\CallTransport;
use App\Models\Call;
use App\Support\Api\ApiError;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a browser call with "Authorization: Bearer dc_call_...".
 * The token belongs to one web call, works only until the call ends or
 * expires, and runs in the call's workspace.
 */
class AuthenticateCallToken
{
    public const ATTRIBUTE = 'docchat.call';

    public function __construct(private CurrentWorkspace $current) {}

    /**
     * "call.token:ended" also lets a call that ended in the last two minutes
     * through, so its last line ("goodbye") can still be spoken.
     */
    public function handle(Request $request, Closure $next, string $mode = ''): Response
    {
        $plain = (string) $request->bearerToken();
        $call = $plain === '' ? null : Call::findByToken($plain);

        if ($call === null || $call->transport !== CallTransport::Web) {
            return ApiError::response(401, 'invalid_call', 'This call link is not valid.');
        }

        $recentlyEnded = $mode === 'ended' && $call->ended_at !== null && $call->ended_at->gt(now()->subMinutes(2));

        if ($call->status->isFinished() && ! $recentlyEnded) {
            return ApiError::response(409, 'call_ended', 'This call has ended.');
        }

        if ($call->isExpired()) {
            return ApiError::response(410, 'call_expired', 'This call link has expired.');
        }

        $request->attributes->set(self::ATTRIBUTE, $call);
        $this->current->set($call->workspace);

        return $next($request);
    }

    public static function call(Request $request): Call
    {
        $call = $request->attributes->get(self::ATTRIBUTE);

        return $call instanceof Call ? $call : abort(401);
    }
}
