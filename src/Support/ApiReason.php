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
 * quote it: its own words and code, with any key or signing secret they
 * quote masked. Never for the payer (Support\CustomerMessage).
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
            $message = trim(sprintf('%s (%s, HTTP %d)', $message, Text::code($error->errorCode, 64), $error->httpStatus));
        }

        return $message !== '' ? $message : $t->trans('no reason given', [], 'Modules.Wajub.Admin');
    }
}
