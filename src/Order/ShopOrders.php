<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The shop's orders, as the module acts on them: PrestaShop's (PrestaShopOrders),
 * or an array in the tests.
 */
interface ShopOrders
{
    /**
     * The order, as one with the other orders its cart became: an order of
     * a cart that ships in several packages (several carriers or
     * warehouses) is read as the whole group, under its first order's id
     * (OrderView), whichever of them `$idOrder` is.
     */
    public function find(int $idOrder): ?OrderView;

    /**
     * The id the group of `$idOrder` goes by (OrderView::$id): the first of
     * the orders its cart became, which holds their Wajub payments, their
     * notes and their lock (`order:<id>`). `$idOrder` itself for an order
     * alone, or one the shop does not have. One query, for a lock taken
     * before the order is read.
     */
    public function leadId(int $idOrder): int;

    /**
     * Adds a payment to the order (an OrderPayment, under its reference),
     * in the order's currency, with the Wajub payment id as its transaction
     * id. Returns its id.
     */
    public function addPayment(OrderView $order, float $amount, string $transactionId): int;

    /**
     * Moves the order (each order of its reference) to a state, once: an
     * order in it already is left as it is. A paid state takes the payments
     * the order holds already, rather than PrestaShop adding one for the
     * rest. `$mail` sends the state's e-mail, when it has one.
     */
    public function setState(OrderView $order, int $stateId, bool $mail): void;

    /**
     * A note for the merchant, shown in the order's Wajub panel, written once
     * per order and `$key` however many requests write it at once. True when
     * this one wrote it. An empty `$message` marks the key as written with
     * nothing to show: a note another path would write says it already.
     */
    public function note(OrderView $order, string $key, string $message): bool;

    /**
     * Takes back the note of `$key`, written ahead of what it warns of,
     * which did not happen after all (Refund\PendingSlips): gone from the
     * order's Wajub panel, and free to be written again.
     */
    public function forgetNote(OrderView $order, string $key): void;

    /**
     * The order's notes, newest first.
     *
     * @return list<array{key: string, message: string, date: string}>
     */
    public function notes(int $idOrder): array;

    /**
     * Records on the order a refund made outside PrestaShop (in the Wajub
     * Dashboard): a credit slip of `$amount`, tax included, with no
     * products, as PrestaShop makes one for an amount alone. The orders a
     * cart that ships in several packages became share it, each up to what
     * is left of its own total, the first one first (Ledger::slipShares()):
     * one slip each. The (first) slip's id.
     */
    public function addSlip(OrderView $order, float $amount): int;

    /** The newest credit slip of any order of the group, 0 for none. */
    public function lastSlip(OrderView $order): int;

    /**
     * What the credit slips of the group's orders refund, tax included: those
     * the merchant made in the back office, through Wajub or not, and those
     * that record refunds made in the Wajub Dashboard, each read as it gives
     * back (slipGivesBack()).
     */
    public function refundedOnOrder(OrderView $order): float;

    /**
     * What one credit slip gives the customer back, tax included, as
     * PrestaShop computed its refund: its products (the order's vouchers
     * taken off when it took them off), or its amount alone, and its
     * shipping (Refund\SlipTotals::givenBack()).
     */
    public function slipGivesBack(int $idOrderSlip): float;

    /**
     * Orders of the module in `$stateId`, the oldest first, at most
     * `$limit`, but those of `$skip`: when each was placed (unix time), by
     * order id.
     *
     * @param list<int> $skip
     *
     * @return array<int, int>
     */
    public function inState(int $stateId, int $limit, array $skip = []): array;

    /** PrestaShop's "Payment accepted". */
    public function paidState(): int;

    /** PrestaShop's "Payment error". */
    public function errorState(): int;

    /** PrestaShop's "Canceled". */
    public function canceledState(): int;

    /** PrestaShop's "Refunded". */
    public function refundedState(): int;
}
