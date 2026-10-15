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
 * Amounts without a locale: the figures grouped by thin spaces, then the
 * currency's sign ("25 000 FCFA", "12.50 €"). What ShopMoneyFormatter falls
 * back on when PrestaShop has no locale to format with (a webhook before
 * the context has one), and what the tests read.
 */
final class PlainMoneyFormatter implements MoneyFormatter
{
    /** @var array<string, string> */
    private const SYMBOLS = [
        'XAF' => 'FCFA',
        'XOF' => 'FCFA',
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'NGN' => '₦',
        'GHS' => 'GH₵',
        'KES' => 'KSh',
        'ZAR' => 'R',
        'CDF' => 'FC',
        'GNF' => 'FG',
        'RWF' => 'FRw',
        'UGX' => 'USh',
        'TZS' => 'TSh',
    ];

    public function format(float $amount, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $decimals = Amount::decimals($currency);

        return number_format(round($amount, $decimals), $decimals, '.', "\u{202F}") . "\u{00A0}" . (self::SYMBOLS[$currency] ?? $currency);
    }
}
