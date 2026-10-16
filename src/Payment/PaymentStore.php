<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where the payments the shop started are kept (PaymentRecord). On
 * PrestaShop, the `wajub_payment` table (DbPaymentStore); in the tests, an
 * array.
 */
interface PaymentStore
{
    /** The payment with this Wajub id, or null when the shop never started it. */
    public function find(string $paymentId): ?PaymentRecord;

    /**
     * The payment prepared for this cart and kind of method, not activated
     * yet, the newest; null when there is none.
     */
    public function preparedFor(int $idCart, string $method): ?PaymentRecord;

    /**
     * Every payment started for the order, prepared payments aside, oldest
     * first.
     *
     * @return list<PaymentRecord>
     */
    public function forOrder(int $idOrder): array;

    /** The order's payment of the moment: the last one started for it, or null. */
    public function current(int $idOrder): ?PaymentRecord;

    /**
     * Payments of orders that went through but could not count yet (their
     * refunds unread), the longest unread first, at most `$limit`, but
     * those of the orders in `$skip`.
     *
     * @param list<int> $skip
     *
     * @return list<PaymentRecord>
     */
    public function uncounted(int $limit, array $skip = []): array;

    /**
     * Those of them that hold `$key` in their metadata, not blank, the
     * longest unread first, at most `$limit`: the payments due to be read
     * again (Order\Recounts::DUE), whatever the payments whose reads ran
     * out, left to the scheduled task, add up to.
     *
     * @return list<PaymentRecord>
     */
    public function uncountedWith(string $key, int $limit): array;

    /**
     * The orders of these shops that hold a payment Wajub may still take or
     * hold money for (`active`, or `uncounted`: it went through without
     * counting yet), at most `$limit`, oldest first: what a shop that is to
     * forget its own key must settle first (Admin\FormHandler `inherit`).
     *
     * @param list<int> $idShops
     *
     * @return list<int>
     */
    public function ordersWithOpenPayments(array $idShops, int $limit): array;

    /**
     * Every payment started for an order of the customer, oldest first:
     * what the customer's data export lists (Privacy\CustomerData).
     *
     * @return list<PaymentRecord>
     */
    public function forCustomer(int $idCustomer): array;

    /**
     * Unlinks the customer from their payments, which stay as the record of
     * their orders' money: the customer's data erased (Privacy\CustomerData).
     * Returns how many were.
     */
    public function forgetCustomer(int $idCustomer): int;

    /** Saves it, new or changed; a new one gets its row id. */
    public function save(PaymentRecord $record): void;
}
