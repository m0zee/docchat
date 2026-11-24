<?php

namespace App\Services\Webhooks;

/**
 * Signature scheme for outgoing webhooks:
 *   X-DocChat-Signature: sha256=<hex HMAC-SHA256 of the raw body, keyed with the secret>
 *   X-DocChat-Timestamp: <unix seconds>, also in the body as "timestamp"
 * Receivers verify the HMAC over the exact raw body, then reject messages
 * whose timestamp is more than 5 minutes from their clock (replays).
 */
class WebhookSigner
{
    public const TOLERANCE_SECONDS = 300;

    public static function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public static function verify(string $body, string $signature, string $timestamp, string $secret, ?int $now = null): bool
    {
        if (! hash_equals(self::sign($body, $secret), $signature)) {
            return false;
        }

        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) && (string) ($decoded['timestamp'] ?? '') === $timestamp;
    }
}
