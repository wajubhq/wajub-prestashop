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
 * Amounts as Wajub counts them, and what a payment collected. The same rules
 * as the WordPress plugin's Support\Amount: a payment counts for what it
 * collected, never more than it asked.
 */
final class Amount
{
    /**
     * Currencies counted without decimals, as ISO 4217 and the Wajub API
     * (TaxService::ZERO_DECIMAL_CURRENCIES) count them.
     *
     * @var list<string>
     */
    private const ZERO_DECIMAL = [
        'XAF', 'XOF', 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XPF',
    ];

    public static function normalize(float $amount, string $currency): float
    {
        return round($amount, self::decimals($currency));
    }

    /** How many digits after the decimal point the currency counts in: 0 for XAF, 2 for EUR. */
    public static function decimals(string $currency): int
    {
        return in_array(strtoupper(trim($currency)), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * What a payment collected toward what it was for: `amount_paid` once
     * Wajub says it, never more than the payment's `amount`. An instalment
     * payment closed early collected less than its amount; a payer who bore
     * the fee paid more, and that fee never pays for a total raised after
     * the payment started. Its `amount` while Wajub says nothing of what was
     * paid, `amount_paid` when it gives no `amount`, 0 when it says neither.
     *
     * @param array<string, mixed> $payment As GET /payments/{id} or its webhook gives it
     */
    public static function collected(array $payment): float
    {
        $amount = is_numeric($payment['amount'] ?? null) ? (float) $payment['amount'] : null;
        if (is_numeric($payment['amount_paid'] ?? null) && (float) $payment['amount_paid'] > 0) {
            return $amount === null ? (float) $payment['amount_paid'] : min($amount, (float) $payment['amount_paid']);
        }

        return $amount ?? 0.0;
    }

    /**
     * What a payment asked: its `amount`, in its `currency`; [0.0, ''] when
     * it does not say.
     *
     * @param array<string, mixed> $payment
     *
     * @return array{0: float, 1: string}
     */
    public static function asked(array $payment): array
    {
        $currency = strtoupper(Text::code($payment['currency'] ?? '', 3));
        if ($currency === '' || !is_numeric($payment['amount'] ?? null)) {
            return [0.0, ''];
        }

        return [(float) $payment['amount'], $currency];
    }
}
