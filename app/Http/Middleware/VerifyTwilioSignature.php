<?php

namespace App\Http\Middleware;

use App\Services\Twilio\TwilioSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only Twilio, signing with this account's auth token, may call the phone
 * line's webhooks. The URL is rebuilt from TWILIO_WEBHOOK_BASE_URL or
 * APP_URL, so it matches what Twilio was told, behind any proxy.
 */
class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $url = TwilioSignature::baseUrl().$request->getRequestUri();

        /** @var array<string, mixed> $params */
        $params = $request->request->all();

        if (! TwilioSignature::valid($url, $params, $request->header('X-Twilio-Signature'), (string) config('docchat.calls.twilio.auth_token'))) {
            return new Response('Invalid signature.', 403, ['Content-Type' => 'text/plain']);
        }

        return $next($request);
    }
}
