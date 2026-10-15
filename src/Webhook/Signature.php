<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wajub's webhook signature: `X-Wajub-Signature: v1=<hex>`, the HMAC
 * SHA-256 of the timestamp (`X-Wajub-Timestamp`), a dot and the raw body,
 * with the endpoint's signing secret. A delivery more than five minutes from
 * the server's clock is refused, so one that was captured cannot be played
 * again later.
 */
final class Signature
{
    public const TOLERANCE = 300;

    /** Whether `signature` is the secret's, over this body and timestamp, made within the tolerance. */
    public static function verify(string $payload, string $signature, string $timestamp, string $secret, ?int $now = null): bool
    {
        if (!self::matches($payload, $signature, $timestamp, $secret)) {
            return false;
        }

        $seconds = (float) $timestamp;

        return is_finite($seconds) && abs(($now ?? time()) - $seconds) <= self::TOLERANCE;
    }

    /**
     * Whether the signature is the secret's, its timestamp aside. Only says
     * why verify() refused a delivery ('stale'); it accepts none.
     */
    public static function matches(string $payload, string $signature, string $timestamp, string $secret): bool
    {
        if ($secret === '' || $timestamp === '' || !str_starts_with($signature, 'v1=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $payload, $secret), substr($signature, 3));
    }

    /** The headers a test sends, as Wajub signs a delivery. */
    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
