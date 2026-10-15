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
 * One refund of a payment the shop started: a row of `wajub_refund`
 * (Install\Schema). What a payment still holds for its order is what it
 * collected, less its refunds that take money back, or will.
 *
 * - `origin` prestashop: asked from the back office (a credit slip,
 *   Refund\RefundSync), known by the key the shop sent it with
 *   (`requestKey`) from before Wajub answers, then by its Wajub id too.
 *   While the call lasts its status is `requested`: it takes nothing back
 *   until Wajub says it exists.
 * - `origin` wajub: made in the Wajub Dashboard, read from its webhooks or
 *   from a payment's refunds, known by its Wajub id.
 *
 * Its status only moves forward (RefundStore::RANK). `orderUnpaid` says
 * the order had never been paid when it was first seen: the payer pays what
 * it gave back again, and it is never a refund of the order. `idOrderSlip`
 * is the credit slip that records it on the order, when one does.
 */
final class RefundRecord
{
    /** The status of the shop's own refund while its call to Wajub lasts. */
    public const REQUESTED = 'requested';

    public const ORIGIN_PRESTASHOP = 'prestashop';

    public const ORIGIN_WAJUB = 'wajub';

    public int $id = 0;

    /** Wajub's id (`rfd_…`), '' while the shop's call for it lasts. */
    public string $refundId = '';

    /** The key the shop sent its own refund with, '' for one made in the Wajub Dashboard. */
    public string $requestKey = '';

    public string $paymentId = '';

    public int $idShop = 0;

    public int $idOrder = 0;

    public int $idOrderSlip = 0;

    /** 'sandbox' or 'live': its payment's. */
    public string $environment = '';

    public string $origin = self::ORIGIN_WAJUB;

    public string $status = 'pending';

    public string $currency = '';

    public float $amount = 0.0;

    public string $reason = '';

    public bool $orderUnpaid = false;

    public string $dateAdd = '';

    /** Whether it takes money back, or will: pending, under way, or done. */
    public function takesMoney(): bool
    {
        return in_array($this->status, RefundStore::LIVE, true);
    }

    public function isShops(): bool
    {
        return $this->origin === self::ORIGIN_PRESTASHOP;
    }
}
