<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The token every back office form and AJAX call of the module carries,
 * checked whatever the shop's "token in URLs" setting says: a hash, with
 * the shop's secret cookie key, of the employee and their back office
 * session. Not PrestaShop's own page token, which PrestaShop 9 makes a new
 * random value at every call (Symfony's CSRF tokens), so a value printed in
 * a page never equals the next one. The settings page and the order page's
 * Wajub panel carry the same one.
 */
final class AdminToken
{
    public static function forEmployee(int $idEmployee, string $sessionToken, string $secret): string
    {
        return hash_hmac('sha256', 'wajub-admin|' . $idEmployee . '|' . $sessionToken, $secret);
    }

    /** The token of the context's employee, '' without one. */
    public static function ofContext(): string
    {
        $context = \Context::getContext();
        if (!$context->employee instanceof \Employee || !$context->cookie instanceof \Cookie) {
            return '';
        }
        $session = isset($context->cookie->session_token) ? (string) $context->cookie->session_token : '';

        return self::forEmployee((int) $context->employee->id, $session, defined('_COOKIE_KEY_') ? (string) _COOKIE_KEY_ : '');
    }
}
