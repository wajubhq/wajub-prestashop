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
    public function find(int $idOrder): ?OrderView;

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
     * The order's notes, newest first.
     *
     * @return list<array{key: string, message: string, date: string}>
     */
    public function notes(int $idOrder): array;

    /**
     * Records on the order a refund made outside PrestaShop (in the Wajub
     * Dashboard): a credit slip of `$amount`, tax included, with no
     * products, as PrestaShop makes one for an amount alone. Its id.
     */
    public function addSlip(OrderView $order, float $amount): int;

    /** The order's newest credit slip, 0 for none. */
    public function lastSlip(OrderView $order): int;

    /**
     * What the order's credit slips refund, tax included: those the
     * merchant made in the back office, through Wajub or not, and those
     * that record refunds made in the Wajub Dashboard.
     */
    public function refundedOnOrder(OrderView $order): float;

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
