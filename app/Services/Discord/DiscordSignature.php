<?php

namespace App\Services\Discord;

/**
 * Discord signs every interaction with Ed25519 over timestamp + raw body,
 * using the application's public key. Requests older than 5 minutes are
 * refused too, so a captured request cannot be replayed later.
 */
class DiscordSignature
{
    public const TOLERANCE_SECONDS = 300;

    public static function verify(string $body, string $signature, string $timestamp, string $publicKey, ?int $now = null): bool
    {
        if (! ctype_xdigit($signature) || strlen($signature) !== 128 || ! ctype_xdigit($publicKey) || strlen($publicKey) !== 64) {
            return false;
        }

        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $signatureBytes = hex2bin($signature);
        $keyBytes = hex2bin($publicKey);

        if ($signatureBytes === false || $keyBytes === false || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($keyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signatureBytes, $timestamp.$body, $keyBytes);
        } catch (\SodiumException) {
            return false;
        }
    }
}
