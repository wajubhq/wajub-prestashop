<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Refund;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A credit slip's totals as PrestaShop writes them (OrderSlipCreator, and
 * OrderSlip::create() before it, the same from 8.1 to 9), and what a slip
 * gives the customer back.
 *
 * - productsTaxIncluded(): the products' total with tax that PrestaShop
 *   writes on a slip whose amounts the merchant typed without tax (a
 *   customer group shown prices without tax): it adds each product's tax
 *   back, rounded as the shop's rounding type says (PS_ROUND_TYPE).
 *   Refund\SlipRequest has Wajub refund that, so that what Wajub gives back
 *   is what the slip says the customer gets.
 * - givenBack(): what a slip made gives back, tax included, read from its
 *   row: what the module counts as refunded on the order
 *   (Order\PrestaShopOrders), and what Wajub refunds of a slip made outside
 *   the order page's forms.
 */
final class SlipTotals
{
    /** PrestaShop's Order::ROUND_ITEM: each unit's price with tax rounded, then times the quantity. */
    public const ROUND_ITEM = 1;

    /** Order::ROUND_LINE: each line's price with tax rounded. */
    public const ROUND_LINE = 2;

    /** Order::ROUND_TOTAL: the tax of each tax rule's total, rounded once. */
    public const ROUND_TOTAL = 3;

    /**
     * The products' total with tax of the slip. `$lines`: each product
     * refunded, its unit price without tax, how many, its tax rules group,
     * and what adds its tax; `$groupTax`: what adds a tax rules group's tax
     * to a sum (ROUND_TOTAL); `$round`: the shop's rounding
     * (Tools::ps_round()).
     *
     * @param list<array{price: float, quantity: int, group: int, tax: callable(float): float}> $lines
     * @param callable(int, float): float $groupTax
     * @param callable(float, int): float $round
     */
    public static function productsTaxIncluded(array $lines, int $roundType, int $precision, callable $groupTax, callable $round): float
    {
        $totals = [];
        foreach ($lines as $line) {
            $price = $line['price'];
            $quantity = $line['quantity'];
            $group = $line['group'];
            $totals[$group] = $totals[$group] ?? 0.0;
            switch ($roundType) {
                case self::ROUND_ITEM:
                    $totals[$group] += $round(($line['tax'])($price), $precision) * $quantity;
                    break;
                case self::ROUND_LINE:
                    $totals[$group] += $round(($line['tax'])($price) * $quantity, $precision);
                    break;
                case self::ROUND_TOTAL:
                    $totals[$group] += $price * $quantity;
                    break;
                default:
                    // As PrestaShop: a rounding type it does not know adds nothing.
            }
        }

        $total = 0.0;
        foreach ($totals as $group => $sum) {
            $total += $roundType === self::ROUND_TOTAL ? $round($groupTax((int) $group, $sum), $precision) : $sum;
        }

        return $total;
    }

    /**
     * What a credit slip gives the customer back, tax included, as
     * PrestaShop computed the refund (OrderRefundCalculator's refunded
     * amount, which Wajub refunds for a slip of the order page's forms): its
     * products' total with tax, or its amount when it has no products (an
     * amount alone: the slips that record refunds made in the Wajub
     * Dashboard), plus its shipping with tax.
     *
     * "Product prices, excluding the voucher" (the forms' choice by default)
     * takes the order's vouchers (`discounts`) off the refund. PrestaShop
     * takes them off one of the slip's products totals: the one with tax
     * when the amounts were typed without tax, but the one WITHOUT tax when
     * they were typed with tax (the customer's group shown prices with tax,
     * the most common case), leaving its products' total with tax, which the
     * order page lists the slip for, short of nothing. The slip's PDF and the
     * refund PrestaShop computed take them off: so does this, by what the
     * slip's products without tax fell short of its lines' (`details_excl`,
     * order_slip_detail), when its products with tax did not fall short of
     * theirs (`details_incl`). A slip that took no voucher off (type 0) is
     * read as it is.
     *
     * @param array{amount: float, products_incl: float, products_excl: float, shipping_incl: float, type: int, details_incl: float, details_excl: float, discounts: float} $slip
     */
    public static function givenBack(array $slip): float
    {
        if ($slip['products_incl'] <= 0) {
            return $slip['amount'] + $slip['shipping_incl'];
        }
        $products = $slip['products_incl'];
        $discounts = $slip['discounts'];
        if ($slip['type'] !== 0 && $discounts > 0) {
            $offWithoutTax = $slip['details_excl'] - $slip['products_excl'];
            $offWithTax = $slip['details_incl'] - $slip['products_incl'];
            if ($offWithoutTax >= $discounts / 2 && $offWithTax < $discounts / 2) {
                $products -= $offWithoutTax;
            }
        }

        return max(0.0, $products + $slip['shipping_incl']);
    }
}
