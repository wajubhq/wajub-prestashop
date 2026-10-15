<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Settings;

use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What a Wajub secret key and a webhook signing secret look like, and how
 * the back office shows one without printing it.
 *
 * A secret key is a prefix, a dot, then 96 characters: `sk_test.` in
 * Sandbox and `sk.` in Live (`rk_test.` and `rk.` for a restricted key).
 * The API reads the environment from that prefix, so a key in the wrong
 * field is refused before it is saved, rather than found out at the first
 * payment.
 */
final class Keys
{
    private const SANDBOX_PREFIXES = ['sk_test.', 'rk_test.'];

    private const LIVE_PREFIXES = ['sk.', 'rk.'];

    private const PUBLISHABLE_PREFIXES = ['pk_test.', 'pk.'];

    /**
     * What was pasted, as the API wants it: no surrounding whitespace, no
     * `Bearer ` copied from a curl example, no tag or control character.
     */
    public static function normalize(string $raw): string
    {
        $key = trim($raw);
        $key = (string) preg_replace('/^bearer\s+/i', '', $key);
        $key = strip_tags($key);

        return trim((string) preg_replace('/[\x00-\x20\x7F]+/', '', $key));
    }

    /**
     * 'sandbox' or 'live' for a secret or restricted key, 'publishable' for
     * a key that must never reach the server, '' for anything else.
     */
    public static function environmentOf(string $key): string
    {
        foreach (self::SANDBOX_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return Settings::SANDBOX;
            }
        }
        foreach (self::LIVE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return Settings::LIVE;
            }
        }
        foreach (self::PUBLISHABLE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return 'publishable';
            }
        }

        return '';
    }

    /** 'restricted' for an `rk` key, 'private' (full access) otherwise. */
    public static function typeOf(string $key): string
    {
        return str_starts_with($key, 'rk') ? 'restricted' : 'private';
    }

    /** The prefix a key of the environment starts with, as the back office shows it. */
    public static function expectedPrefix(string $environment): string
    {
        return $environment === Settings::SANDBOX ? 'sk_test.' : 'sk.';
    }

    /**
     * Why `key` cannot be the secret key of `environment`, in words for the
     * merchant, or '' when its shape is right.
     */
    public static function formatProblem(string $key, string $environment, Translator $t): string
    {
        $found = self::environmentOf($key);
        if ($found === $environment) {
            return '';
        }
        if ($found === 'publishable') {
            return $t->trans('This is a publishable key. Paste the secret key instead: it starts with “%prefix%”.', ['%prefix%' => self::expectedPrefix($environment)], 'Modules.Wajub.Admin');
        }
        if ($environment === Settings::SANDBOX && $found === Settings::LIVE) {
            return $t->trans('This is a live key: it takes real payments. Paste it in the Live key field, and use a key that starts with “sk_test.” here.', [], 'Modules.Wajub.Admin');
        }
        if ($environment === Settings::LIVE && $found === Settings::SANDBOX) {
            return $t->trans('This is a sandbox key: it only takes test payments. Paste it in the Sandbox key field, and use a key that starts with “sk.” here.', [], 'Modules.Wajub.Admin');
        }

        return $t->trans('This does not look like a Wajub secret key. It should start with “%prefix%”. Copy it again from the Wajub Dashboard.', ['%prefix%' => self::expectedPrefix($environment)], 'Modules.Wajub.Admin');
    }

    /** Why `secret` cannot be the webhook signing secret of `environment`, or ''. */
    public static function webhookSecretProblem(string $secret, string $environment, Translator $t): string
    {
        $isSandbox = str_starts_with($secret, 'whsec_test_');
        $isSecret = str_starts_with($secret, 'whsec_');
        if ($environment === Settings::SANDBOX && $isSandbox) {
            return '';
        }
        if ($environment === Settings::LIVE && $isSecret && !$isSandbox) {
            return '';
        }
        if ($environment === Settings::LIVE && $isSandbox) {
            return $t->trans('This is the signing secret of a sandbox webhook. Paste it in the Sandbox field, and use the live webhook’s secret (whsec_…) here.', [], 'Modules.Wajub.Admin');
        }
        if ($environment === Settings::SANDBOX && $isSecret) {
            return $t->trans('This is the signing secret of a live webhook. Paste it in the Live field, and use the sandbox webhook’s secret (whsec_test_…) here.', [], 'Modules.Wajub.Admin');
        }

        return $t->trans('This does not look like a webhook signing secret. It starts with whsec_ and is shown when the webhook is created in the Wajub Dashboard.', [], 'Modules.Wajub.Admin');
    }

    /**
     * A saved secret as the back office shows it: its prefix, dots, and its
     * last four characters, so the merchant can tell which key is saved
     * without the page ever holding it. A secret too short to keep anything
     * hidden shows dots only.
     */
    public static function mask(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }

        return self::prefixOf($secret) . str_repeat("\u{2022}", 4) . self::visibleTail($secret);
    }

    /**
     * The last four characters mask() shows, or '' when what follows the
     * prefix is too short for four characters to keep the rest hidden.
     */
    public static function visibleTail(string $secret): string
    {
        $secret = trim($secret);
        $rest = substr($secret, strlen(self::prefixOf($secret)));

        return strlen($rest) >= 16 ? substr($rest, -4) : '';
    }

    /** Never the key itself: enough to tell two keys apart, in a log line or an origin. */
    public static function fingerprint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    /** `sk_test.`, `sk.`, `whsec_test_`, `whsec_`: the part of a secret that says what it is. */
    private static function prefixOf(string $secret): string
    {
        return preg_match('/^(?:[a-z]+_test\.|[a-z]+\.|whsec_test_|whsec_)/i', $secret, $match) === 1 ? $match[0] : '';
    }
}
