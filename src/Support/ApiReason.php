<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Support;

use Wajub\PrestaShop\Api\ApiException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wajub's reason for a refusal, as the merchant's notes and the back office
 * quote it: its own words, its code when it gave one, and the HTTP status,
 * with any key or signing secret they quote masked. Never for the payer
 * (Support\CustomerMessage).
 */
final class ApiReason
{
    public static function of(\Throwable $error, Translator $t, int $length = 200): string
    {
        $message = Text::line($error->getMessage());
        // Keys (`sk_test.…`, `sk.…`, `rk.…`, `pk.…`, older `sk_live_…`) and
        // signing secrets (`whsec_test_…`, `whsec_…`) keep their prefix only.
        $message = (string) preg_replace('/\b((?:sk|rk|pk)(?:_test|_live)?[._]|whsec_(?:test_|live_)?)[A-Za-z0-9]{6,}/', '$1…', $message);
        $message = mb_substr($message, 0, $length);
        if ($error instanceof ApiException) {
            // Wajub's own code when it gave one; the client's stand-in for
            // none (`http_422`) would only say the status twice.
            $code = Text::code($error->errorCode, 64);
            // Within Wajub's sentence: "… (4,000.00 XAF) (HTTP 422)", never
            // "… (4,000.00 XAF). (HTTP 422)" once a sentence follows it.
            $message = rtrim($message, '. ');
            $message = $code !== '' && $code !== 'http_' . $error->httpStatus
                ? trim(sprintf('%s (%s, HTTP %d)', $message, $code, $error->httpStatus))
                : trim(sprintf('%s (HTTP %d)', $message, $error->httpStatus));
        }

        return $message !== '' ? $message : $t->trans('no reason given', [], 'Modules.Wajub.Admin');
    }
}
