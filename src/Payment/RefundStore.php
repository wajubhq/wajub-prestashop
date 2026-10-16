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
 * The refunds of the shop's payments (`wajub_refund`, RefundRecord): what a
 * payment still holds for its order is what it collected, less those that
 * take money back or will (pending, under way, done).
 */
interface RefundStore
{
    /** The statuses of a refund that takes money back, or will. */
    public const LIVE = ['pending', 'processing', 'succeeded'];

    /** How far along a refund's life a status is: a status never replaces one further along. */
    public const RANK = ['pending' => 0, 'processing' => 1, 'failed' => 2, 'cancelled' => 2, 'succeeded' => 3];

    /**
     * Notes a refund as Wajub gives it (`GET /payments/{id}/refunds`, or a
     * refund's webhook), once by its id, its status moving only forward.
     * One the shop asked for itself is found by the key it carries
     * (`metadata.request_key`), its call answered or not: it takes its
     * Wajub id then, and is never noted a second time. `$orderUnpaid` is
     * kept the first time only. True the first time.
     *
     * @param array<string, mixed> $refund
     */
    public function note(PaymentRecord $payment, array $refund, string $origin, bool $orderUnpaid = false): bool;

    /** What the live refunds of a payment take back, in `$currency`. */
    public function refunded(string $paymentId, string $currency): float;

    /** What the refunds of a payment that succeeded gave back, in `$currency`. */
    public function succeeded(string $paymentId, string $currency): float;

    public function find(string $refundId): ?RefundRecord;

    public function findByKey(string $requestKey): ?RefundRecord;

    /**
     * Every refund of the order's payments, oldest first.
     *
     * @return list<RefundRecord>
     */
    public function forOrder(int $idOrder): array;

    /**
     * Notes a refund the shop is about to ask for, under the key it sends it
     * with, before the call: its webhook, which may arrive before the call
     * returns, finds it by that key.
     */
    public function requested(PaymentRecord $payment, string $requestKey, float $amount, string $reason): void;

    /**
     * What Wajub answered the shop's call: the refund's id and status.
     *
     * @param array<string, mixed> $refund
     */
    public function answered(string $requestKey, array $refund): void;

    /** Forgets a refund the shop asked for and Wajub refused: no money moved. */
    public function forget(string $requestKey): void;

    /** The credit slip that records the refund on its order. */
    public function linkSlip(RefundRecord $refund, int $idOrderSlip): void;

    /**
     * Refunds the shop asked for that went through, or may still (its call
     * unanswered included), and that no credit slip records, asked between
     * `$after` and `$before` (Y-m-d H:i:s), newest first, at most `$limit`:
     * a PHP error ended their request before PrestaShop made the slip, and
     * no webhook came (Order\Cleanup).
     *
     * @return list<RefundRecord>
     */
    public function shopsWithoutSlip(string $after, string $before, int $limit): array;
}
