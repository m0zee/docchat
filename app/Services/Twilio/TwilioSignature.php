<?php

namespace App\Services\Twilio;

/**
 * Twilio's X-Twilio-Signature: base64(HMAC-SHA1(auth token, URL followed by
 * every POST parameter name and value, sorted by name)).
 */
final class TwilioSignature
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function sign(string $url, array $params, string $authToken): string
    {
        ksort($params, SORT_STRING);
        $data = $url;

        foreach ($params as $name => $value) {
            foreach ((array) $value as $item) {
                $data .= $name.(is_scalar($item) ? (string) $item : '');
            }
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function valid(string $url, array $params, ?string $signature, string $authToken): bool
    {
        return $authToken !== ''
            && is_string($signature) && $signature !== ''
            && hash_equals(self::sign($url, $params, $authToken), $signature);
    }

    /**
     * The address Twilio calls: TWILIO_WEBHOOK_BASE_URL (a tunnel, say) or APP_URL.
     */
    public static function baseUrl(): string
    {
        return rtrim((string) (config('docchat.calls.twilio.webhook_base_url') ?: config('app.url')), '/');
    }

    public static function url(string $path): string
    {
        return self::baseUrl().'/'.ltrim($path, '/');
    }
}
