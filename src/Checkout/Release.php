<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Checkout;

use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Order\HeldConfirmations;
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\OpenPayments;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Order\ShopOrders;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The payer leaves the payment of an order placed at checkout, or it ended
 * unpaid: the order is given back as a cart, for the payer to pay another
 * way, or the same way again.
 *
 * PrestaShop creates the order when the payer places it, and a placed cart
 * is never paid twice: a payer whose payment ended unpaid (cancelled on
 * Wajub's page, expired, refused, or left with "Use another payment
 * method") would find an empty cart. So the order is cancelled, with a note
 * for the merchant, and its cart comes back as a new one, the same products
 * and addresses, at the checkout, with a message saying why.
 *
 * Never while a payment of the order may still be charged: Wajub is asked
 * first, of each one still open (Order\OpenPayments). A payment that went
 * through settles the order; one Wajub is still taking (an approval on its
 * way on the payer's phone, an instalment) sends the payer to the order's
 * confirmation page, which follows it; one that cannot be read changes
 * nothing. One nobody paid yet is cancelled with Wajub first, so it can
 * never be approved afterwards. An order Wajub holds money for is never
 * cancelled, nor one paid once (refunded since, or cancelled by the
 * merchant).
 */
final class Release
{
    public const TO_CONFIRMATION = 'confirmation';

    public const TO_CHECKOUT = 'checkout';

    /** Nothing changed: Wajub could not be asked. The payer may try again. */
    public const STAY = 'stay';

    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var OpenPayments */
    private $open;

    /** @var Ledger */
    private $ledger;

    /** @var Storefront */
    private $storefront;

    /** @var Translator */
    private $t;

    /** @var Logger */
    private $logger;

    /** @var HeldConfirmations */
    private $confirmations;

    public function __construct(ShopOrders $orders, PaymentStore $payments, OpenPayments $open, Ledger $ledger, Storefront $storefront, Translator $t, Logger $logger, HeldConfirmations $confirmations)
    {
        $this->orders = $orders;
        $this->payments = $payments;
        $this->open = $open;
        $this->ledger = $ledger;
        $this->storefront = $storefront;
        $this->t = $t;
        $this->logger = $logger;
        $this->confirmations = $confirmations;
    }

    /**
     * The payer leaves the order's payment (`$reason`: 'switch' for another
     * method, 'cancelled', 'expired' or 'failed' as the payment form ended
     * it, 'unavailable' when the method could not take the order). The
     * caller holds the order's lock; the order is read again here, as
     * another request may have given it back, or paid it, while the caller
     * waited for the lock.
     *
     * `$read`: what Wajub said of the order's payment a moment ago (the
     * payer's return reads it first); read afresh otherwise. Every other
     * payment of the order still open is read too (Order\OpenPayments): a
     * second tab may have started one beside it.
     *
     * The order goes back to being a cart only for its own customer, signed
     * in where they leave it: PrestaShop signs a guest out on the order
     * confirmation page, and the link may be opened in another browser. Any
     * other visitor is told to fill a cart again, and the order is left as
     * it is, for the merchant.
     *
     * @param array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true}|null $read
     *
     * @return array{to: string, url: string, message: string, tone: string} `tone`: how the message reads, info or error
     */
    public function leave(OrderView $order, string $reason, ?array $read = null): array
    {
        $order = $this->orders->find($order->id) ?? $order;
        $confirmation = ['to' => self::TO_CONFIRMATION, 'url' => $this->storefront->confirmation($order), 'message' => '', 'tone' => 'info'];
        // Paid once, then refunded or cancelled by the merchant (neither
        // state counts as paid): a stale tab's form never cancels it, nor
        // gives its cart back to be ordered again.
        if ($order->paid || $order->paidOnce || !$order->isWajub()) {
            return $confirmation;
        }
        // Given back already (a second click, another tab): its cart is the
        // payer's again, at the checkout.
        if ($order->stateId === $this->orders->canceledState()) {
            return ['to' => self::TO_CHECKOUT, 'url' => $this->storefront->checkout(), 'message' => '', 'tone' => 'info'];
        }

        $current = $this->payments->current($order->id);
        $handOver = $this->storefront->canRestoreCart($order);
        // A payment nobody paid yet is cancelled only for a payer who gets
        // the cart back: anyone else leaves it to the confirmation page.
        $settled = $this->open->settle($order, Settlement::SEEN_NOW, $read !== null && $current !== null ? [$current->paymentId => $read] : [], $handOver);
        switch ($settled['verdict']) {
            case OpenPayments::PAID:
            case OpenPayments::WAIT:
                return $confirmation;
            case OpenPayments::UNCHECKED:
                return $this->unchecked();
        }
        // Ended on its own (refused, expired, cancelled on Wajub's page or
        // on the payer's phone): the payer reads why, unless they chose to
        // pay another way, as Wajub says it, not as the payment form did
        // (Wajub.js shows a payment cancelled on the phone as failed). One
        // settled before the payer left (by the status the page follows, or
        // its webhook) was not read again: as it was recorded then.
        if ($current !== null && $reason !== 'switch' && !in_array($current->paymentId, $settled['cancelled'], true)) {
            $ended = $settled['statuses'][$current->paymentId] ?? '';
            if ($ended === '') {
                $known = $this->payments->find($current->paymentId);
                $ended = $known !== null && $known->state === PaymentRecord::FINAL && Client::isFailedStatus($known->status) ? $known->status : '';
            }
            if ($ended !== '' && $ended !== 'gone') {
                $reason = self::reasonOf($ended);
            }
        }

        // Money Wajub took, or a payment that went through and cannot count
        // yet: the order stays, whatever the payer chose.
        $order = $this->orders->find($order->id) ?? $order;
        if ($order->paid || $this->ledger->holdsMoney($order) || $this->ledger->uncounted($order) !== []) {
            return $confirmation;
        }

        // The merchant reads why in words, never the scripts' code.
        $why = self::why($reason, $this->t);
        if (!$handOver) {
            $this->orders->note($order, 'kept_' . md5((string) ($current !== null ? $current->paymentId : '')), $current !== null
                ? $this->t->trans('The customer left Wajub payment %payment% without paying (%reason%), signed out or in another browser: the order could not be given back to them as a cart, and was left as it is. Cancel it if it is not paid.', ['%payment%' => $current->paymentId, '%reason%' => $why], 'Modules.Wajub.Admin')
                : $this->t->trans('The customer left the payment without paying (%reason%), signed out or in another browser: the order could not be given back to them as a cart, and was left as it is. Cancel it if it is not paid.', ['%reason%' => $why], 'Modules.Wajub.Admin'));
            $this->logger->info('Order kept: its cart cannot reach this visitor', ['order' => $order->id, 'reason' => $reason]);

            return ['to' => self::TO_CHECKOUT, 'url' => $this->storefront->checkout(), 'message' => CheckoutStrings::notRestored($this->t), 'tone' => 'error'];
        }

        $this->orders->setState($order, $this->orders->canceledState(), false);
        $this->orders->note($order, 'released', $current !== null
            ? $this->t->trans('The customer left Wajub payment %payment% without paying (%reason%): the order was cancelled, and its cart given back to the customer to place the order again.', ['%payment%' => $current->paymentId, '%reason%' => $why], 'Modules.Wajub.Admin')
            : $this->t->trans('The customer left the payment without paying (%reason%): the order was cancelled, and its cart given back to the customer to place the order again.', ['%reason%' => $why], 'Modules.Wajub.Admin'));
        // Never paid from now on: the order placed again from its cart has a
        // confirmation of its own. The one held for this order, with the
        // customer's name, e-mail address and addresses, goes.
        $this->confirmations->forgetOrder($order);
        $restored = $this->storefront->restoreCart($order);
        $this->logger->info('Order given back as a cart', ['order' => $order->id, 'reason' => $reason, 'restored' => $restored]);

        return [
            'to' => self::TO_CHECKOUT,
            'url' => $this->storefront->checkout(),
            // The copy could not be made (the cart is gone): the payer is
            // not told it is back.
            'message' => $restored ? CheckoutStrings::released($reason, $this->t) : CheckoutStrings::notRestored($this->t),
            'tone' => $restored && in_array($reason, ['switch', 'cancelled'], true) ? 'info' : 'error',
        ];
    }

    /**
     * Nothing changed: Wajub could not be asked, and the payment may still
     * be charged.
     *
     * @return array{to: string, url: string, message: string, tone: string}
     */
    private function unchecked(): array
    {
        return ['to' => self::STAY, 'url' => '', 'message' => $this->t->trans('We could not check your payment with Wajub. Please try again in a moment.', [], 'Modules.Wajub.Shop'), 'tone' => 'error'];
    }

    /**
     * Why the payer left, as the merchant's note says it: `$reason` as
     * leave() takes it, in words of the merchant's language.
     */
    public static function why(string $reason, Translator $t): string
    {
        switch ($reason) {
            case 'switch':
                return $t->trans('they chose another payment method', [], 'Modules.Wajub.Admin');
            case 'cancelled':
                return $t->trans('the payment was cancelled', [], 'Modules.Wajub.Admin');
            case 'expired':
                return $t->trans('the payment expired', [], 'Modules.Wajub.Admin');
            case 'unavailable':
                return $t->trans('the payment method could not take the order', [], 'Modules.Wajub.Admin');
            default:
                return $t->trans('the payment was declined or failed', [], 'Modules.Wajub.Admin');
        }
    }

    /** The reason the payer reads for a payment that ended in this status. */
    public static function reasonOf(string $status): string
    {
        switch (strtolower($status)) {
            case 'expired':
                return 'expired';
            case 'canceled':
            case 'cancelled':
            case 'abandoned':
                return 'cancelled';
            default:
                return 'failed';
        }
    }
}
