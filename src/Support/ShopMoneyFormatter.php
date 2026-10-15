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
 * Amounts as PrestaShop formats its own prices: the locale of the page (its
 * language's CLDR data, the shop's currency signs), so a note or a line the
 * module writes reads like the order next to it.
 */
final class ShopMoneyFormatter implements MoneyFormatter
{
    public function format(float $amount, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        try {
            $locale = \Tools::getContextLocale(\Context::getContext());
            $formatted = (string) $locale->formatPrice(round($amount, Amount::decimals($currency)), $currency);
            if ($formatted !== '') {
                return $formatted;
            }
        } catch (\Throwable $e) {
            // No locale in this context, or a currency it does not know.
        }

        return (new PlainMoneyFormatter())->format($amount, $currency);
    }
}
