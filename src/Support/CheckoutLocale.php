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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The language Wajub's payment form and hosted page open in: the page's,
 * when Wajub's checkout is written in it. Any other leaves the choice to
 * Wajub (the payer's browser).
 */
final class CheckoutLocale
{
    /** The languages the Wajub checkout is written in (checkout.wajub, SUPPORTED_LOCALES). */
    public const SUPPORTED = ['fr', 'en', 'es', 'pt', 'ar'];

    /** The checkout language a language code names ("fr", "fr-FR", "EN"), or ''. */
    public static function of(string $code): string
    {
        $language = strtolower(substr(trim($code), 0, 2));

        return in_array($language, self::SUPPORTED, true) ? $language : '';
    }

    /**
     * The hosted payment page's address, opened in `$language`; unchanged
     * for '' or a language the checkout does not have.
     */
    public static function hostedPageUrl(string $url, string $language): string
    {
        $language = self::of($language);
        if ($url === '' || $language === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'locale=' . rawurlencode($language);
    }
}
