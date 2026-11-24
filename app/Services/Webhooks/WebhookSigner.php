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

    /**
     * The JSON body every signed delivery uses.
     *
     * @param  array<string, mixed>  $data
     */
    public static function envelope(string $id, string $event, int $timestamp, int $workspaceId, array $data): string
    {
        return (string) json_encode([
            'id' => $id,
            'event' => $event,
            'timestamp' => $timestamp,
            'workspace_id' => $workspaceId,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Headers for a signed delivery of $body.
     *
     * @return array<string, string>
     */
    public static function headers(string $id, string $event, int $timestamp, string $body, string $secret): array
    {
        return [
            'User-Agent' => 'DocChat-Webhooks/1.0',
            'X-DocChat-Event' => $event,
            'X-DocChat-Delivery' => $id,
            'X-DocChat-Timestamp' => (string) $timestamp,
            'X-DocChat-Signature' => self::sign($body, $secret),
        ];
    }

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
