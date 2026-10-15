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

use Wajub\PrestaShop\Api\ApiConnectionException;
use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\AuthenticationException;
use Wajub\PrestaShop\Api\PermissionException;
use Wajub\PrestaShop\Api\RateLimitException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What a payer reads when a payment cannot start: what went wrong, in words
 * that tell them what to do next, and never the API's own message, which is
 * written for the merchant (and can name their account or their keys). The
 * detail goes to the log, for the merchant.
 */
final class CustomerMessage
{
    /** Errors that are the shop's configuration, not anything the payer typed. */
    private const MERCHANT_SIDE_CODES = [
        'merchant_restricted',
        'merchant_not_verified',
        'live_mode_only',
        'not_configured',
    ];

    public static function forPaymentStart(\Throwable $error, Translator $t, Logger $logger): string
    {
        $logger->error('Payment could not be started', [
            'exception' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error instanceof ApiException ? $error->errorCode : '',
        ]);

        if ($error instanceof ApiConnectionException || $error instanceof RateLimitException) {
            return self::temporarilyUnavailable($t);
        }
        if ($error instanceof AuthenticationException || $error instanceof PermissionException) {
            return self::shopUnavailable($t);
        }
        if ($error instanceof ApiException && in_array($error->errorCode, self::MERCHANT_SIDE_CODES, true)) {
            return self::shopUnavailable($t);
        }

        if ($error instanceof ApiException) {
            switch (self::invalidField($error)) {
                case 'amount':
                    return $t->trans('This amount cannot be paid online. Please contact the shop or change your order.', [], 'Modules.Wajub.Shop');
                case 'currency':
                    return $t->trans('This currency is not accepted for online payment. Please contact the shop.', [], 'Modules.Wajub.Shop');
                case 'phone':
                    return $t->trans('Your phone number looks invalid. Check it in your address, including the country code (e.g. +237…), and try again.', [], 'Modules.Wajub.Shop');
                case 'email':
                    return $t->trans('Your email address looks invalid. Check it and try again.', [], 'Modules.Wajub.Shop');
            }
            if ($error->httpStatus >= 500) {
                return self::temporarilyUnavailable($t);
            }
        }

        return $t->trans('Your payment could not be started. Please try again in a moment.', [], 'Modules.Wajub.Shop');
    }

    public static function temporarilyUnavailable(Translator $t): string
    {
        return $t->trans('The payment service is temporarily unavailable. Please try again in a few moments.', [], 'Modules.Wajub.Shop');
    }

    public static function shopUnavailable(Translator $t): string
    {
        return $t->trans('Online payment is temporarily unavailable in this shop. Please contact the shop.', [], 'Modules.Wajub.Shop');
    }

    /** The first field a validation error names: amount, currency, phone, email, or ''. */
    private static function invalidField(ApiException $error): string
    {
        $errors = is_array($error->body['errors'] ?? null) ? array_keys($error->body['errors']) : [];
        foreach ($errors as $key) {
            $key = strtolower((string) $key);
            if (str_starts_with($key, 'amount')) {
                return 'amount';
            }
            if ($key === 'currency') {
                return 'currency';
            }
            if (str_ends_with($key, 'phone')) {
                return 'phone';
            }
            if (str_ends_with($key, 'email')) {
                return 'email';
            }
        }

        return $error->errorCode === 'amount_out_of_range' ? 'amount' : '';
    }
}
