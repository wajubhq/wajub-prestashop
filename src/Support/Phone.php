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
 * A phone number as Wajub takes a payer's: international, `+` then digits.
 * The country code is the one of the country of the address the number was
 * given with (PrestaShop's `call_prefix`), never one guessed from the
 * number's length.
 */
final class Phone
{
    /** The fewest digits a national number has once its country code is set apart. */
    private const NATIONAL_MIN = 8;

    /**
     * "+237677123456" from "677 12 34 56" and 237, from "00237 677…", from
     * "+237 677…" or from "237677…"; a leading trunk 0 goes ("06 12…" in
     * France is "+336 12…"). '' when there is nothing to make a number of.
     */
    public static function international(string $raw, int $callPrefix): string
    {
        $raw = trim($raw);
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($raw, '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }

        $prefix = $callPrefix > 0 ? (string) $callPrefix : '';
        if ($prefix === '') {
            // No country to read it in: only the digits, as given.
            return $digits;
        }
        if (str_starts_with($digits, $prefix) && strlen($digits) - strlen($prefix) >= self::NATIONAL_MIN) {
            return '+' . $digits;
        }
        $national = ltrim($digits, '0');

        return $national !== '' ? '+' . $prefix . $national : '';
    }
}
