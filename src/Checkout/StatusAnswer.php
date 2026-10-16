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
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Order\ShopOrders;
use Wajub\PrestaShop\Payment\ChannelLabels;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\MoneyFormatter;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where an order's payment stands, for the pages that follow it: the
 * checkout's payment form while the payer pays, and the order confirmation
 * page while a Mobile Money approval is on its way (controllers/front/status.php).
 * A port of the WordPress plugin's Gateway::getEmbedStatusResult().
 *
 * The answer: `status` paid | pending | failed, `processing` (Wajub is
 * taking the payment: a payer who leaves now may still be charged, and is
 * sent to the confirmation page rather than offered to pay again),
 * `partial` with `message` (part of the order paid), `reason` for a failure
 * (failed, cancelled, expired, order_cancelled), `retry` (the payer may give
 * the order back as a cart and pay again), `pay` (the payer may pay what is
 * left of an order part of which is paid: controllers/front/pay.php), and
 * `redirect`, the order's confirmation page.
 *
 * Reading the payment settles the order as the webhook would
 * (Order\Settlement): a paid order is paid by the time its confirmation page
 * opens. Wajub's answer is kept a few seconds for every page that asks.
 * The caller holds the order's lock.
 */
final class StatusAnswer
{
    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var Settlement */
    private $settlement;

    /** @var Ledger */
    private $ledger;

    /** @var Storefront */
    private $storefront;

    /** @var Translator */
    private $t;

    /** @var MoneyFormatter */
    private $money;

    public function __construct(ShopOrders $orders, PaymentStore $payments, Settlement $settlement, Ledger $ledger, Storefront $storefront, Translator $t, MoneyFormatter $money)
    {
        $this->orders = $orders;
        $this->payments = $payments;
        $this->settlement = $settlement;
        $this->ledger = $ledger;
        $this->storefront = $storefront;
        $this->t = $t;
        $this->money = $money;
    }

    /**
     * @return array<string, mixed>
     */
    public function answer(OrderView $order): array
    {
        if ($order->paid) {
            return $this->paid($order);
        }
        if (!$order->isWajub()) {
            return ['status' => 'pending', 'processing' => false, 'redirect' => $this->storefront->confirmation($order)];
        }

        $current = $this->payments->current($order->id);
        if ($order->stateId === $this->orders->canceledState()) {
            // Unless Wajub took part of it: its items back in a cart would
            // be paid a second time.
            if ($this->ledger->holdsMoney($order)) {
                return ['status' => 'failed', 'reason' => 'order_cancelled', 'partial' => true, 'message' => $this->t->trans('Part of this order was paid before it was cancelled. Contact the shop to complete it.', [], 'Modules.Wajub.Shop')];
            }

            return $this->failed($order, 'order_cancelled', false);
        }
        // Paid once, then refunded or set back by the merchant: nothing is
        // settled from here any more, and nothing is left to pay.
        if ($current === null || $order->paidOnce) {
            return ['status' => 'pending', 'processing' => false, 'redirect' => $this->storefront->confirmation($order)];
        }

        $read = $this->settlement->outcome($current);
        if (Settlement::namesAnotherOrder($order, $read['transaction'])) {
            return ['status' => 'pending', 'processing' => false, 'redirect' => $this->storefront->confirmation($order)];
        }
        $status = strtolower($read['status']);

        if ($read['outcome'] === Client::OUTCOME_SUCCEEDED) {
            $applied = $this->settlement->apply($order, $current, $read, Settlement::SEEN_NOW);
            $order = $this->orders->find($order->id) ?? $order;
            if ($order->paid) {
                return $this->paid($order);
            }
            // Paid short of the order's total: it counts toward it, and the
            // payer reads what is left to pay.
            if ($applied === Settlement::OUTCOME_SHORT && $this->ledger->isCounted($order, $current->paymentId)) {
                return $this->partial($order, $this->payerLine($order, $current->environment));
            }
            // In another currency, or not counted yet: not paid. The payer
            // was charged: they follow the order, never offered a second
            // payment from here.
            $status = 'processing';
        } elseif ($read['outcome'] === Client::OUTCOME_FAILED) {
            $this->settlement->apply($order, $current, $read);
            $order = $this->orders->find($order->id) ?? $order;
            $reason = Settlement::isAbandoned($status) ? ($status === 'expired' ? 'expired' : 'cancelled') : 'failed';

            return $this->failed($order, $reason, !$this->ledger->holdsMoney($order));
        } else {
            $this->settlement->remember($current, $read['transaction']);
        }

        // Between two instalments of a payment paid in instalments.
        if ($status === 'partial' || ($status !== 'processing' && Ledger::openWithMoney($read['transaction']))) {
            return $this->partial($order, $this->payerLine($order, $current->environment, Ledger::paidSoFar($read['transaction'])));
        }

        return [
            'status' => 'pending',
            'processing' => in_array($status, ['processing', 'partial'], true),
            'redirect' => $this->storefront->confirmation($order),
        ];
    }

    /**
     * What the order's confirmation page shows of its payment
     * (Wajub::hookDisplayPaymentReturn()), for an order not paid yet in one
     * of `$states` (waiting for its payment, part paid, payment error):
     * `panel` 'waiting' while its payment of the moment is being confirmed,
     * which the page follows (order-status.js), saying what was received
     * already (the rest under way: `paid_so_far`); 'partial' once a payment
     * short of the total counts, with what is left, and "Pay the rest" when
     * the order can be paid from its pages (`can_pay`); '' otherwise.
     * `phone`: a payment the payer may still approve on their phone (Mobile
     * Money's form, or Wajub's page with Mobile Money or a method not known
     * yet), never a card's.
     *
     * @param list<int> $states
     *
     * @return array{panel: string, phone: bool, paid_so_far: string, can_pay: bool}
     */
    public function confirmationPanel(OrderView $order, array $states): array
    {
        $current = $this->payments->current($order->id);
        if ($order->paid || $current === null || !in_array($order->stateId, $states, true)) {
            return ['panel' => '', 'phone' => false, 'paid_so_far' => '', 'can_pay' => false];
        }
        $line = $this->payerLine($order, $current->environment);
        $waiting = $line === '' || !$current->isCounted();

        return [
            'panel' => $waiting ? 'waiting' : 'partial',
            'phone' => $current->method === Methods::MOBILE_MONEY
                || ($current->mode === PaymentRecord::MODE_HOSTED && $current->method !== Methods::CARD && !ChannelLabels::isCard($current->channel)),
            'paid_so_far' => $line,
            'can_pay' => !$waiting && $this->payable($order),
        ];
    }

    /**
     * "4 000 FCFA already received; 6 000 FCFA left to pay." for the payer;
     * '' while nothing was received. `$open`: what a payment still paid in
     * instalments collected so far.
     *
     * A cart that ships in several packages became several orders, which
     * the customer's account lists apart under one reference, each with
     * its own total: the amounts are those of them all, and the line says
     * so, or the payer reading it on each order would think each owes it.
     */
    public function payerLine(OrderView $order, string $environment, float $open = 0.0): string
    {
        $received = Amount::normalize($this->ledger->held($order, $environment) + $open, $order->currency);
        if ($received <= 0) {
            return '';
        }
        $left = max(0.0, Amount::normalize(Ledger::total($order) - $received, $order->currency));
        $parameters = [
            '%received%' => $this->money->format($received, $order->currency),
            '%left%' => $this->money->format($left, $order->currency),
        ];
        if (count($order->ids) > 1) {
            return $this->t->trans('%received% already received; %left% left to pay, for all your orders of reference %reference% together.', $parameters + ['%reference%' => $order->reference], 'Modules.Wajub.Shop');
        }

        return $this->t->trans('%received% already received; %left% left to pay.', $parameters, 'Modules.Wajub.Shop');
    }

    /**
     * Whether the payer may pay the order from its pages ("Pay the rest",
     * controllers/front/pay.php): a Wajub order, neither paid nor cancelled,
     * part of which is paid, or whose payment did not go through, and no
     * payment of which Wajub is taking now (one being approved, or with the
     * payer: they follow it) or could not count yet. Between two instalments
     * the plan is taken up again.
     */
    public function payable(OrderView $order): bool
    {
        // Paid once: refunded since, or set back by the merchant, it is
        // never paid again from here.
        if (!$order->isWajub() || $order->paidOnce || $order->stateId === $this->orders->canceledState() || $this->ledger->uncounted($order) !== []) {
            return false;
        }
        $current = $this->payments->current($order->id);
        if ($current === null || $current->state !== PaymentRecord::ACTIVE) {
            return $current !== null || $this->ledger->holdsMoney($order);
        }

        return $current->status === 'partial';
    }

    /**
     * @return array<string, mixed>
     */
    private function paid(OrderView $order): array
    {
        return ['status' => 'paid', 'redirect' => $this->storefront->confirmation($order)];
    }

    /**
     * Part of the order is paid, the rest waits: still pending, under way as
     * far as the scripts go (they send the payer to the confirmation page,
     * which says it).
     *
     * @return array<string, mixed>
     */
    private function partial(OrderView $order, string $message): array
    {
        return ['status' => 'pending', 'processing' => true, 'partial' => true, 'message' => $message, 'pay' => $this->payable($order), 'redirect' => $this->storefront->confirmation($order)];
    }

    /**
     * A payment that did not go through, and what the payer can do: give the
     * order back as a cart and pay again (`retry`), unless Wajub holds money
     * for it.
     *
     * @return array<string, mixed>
     */
    private function failed(OrderView $order, string $reason, bool $retry): array
    {
        switch ($reason) {
            case 'cancelled':
                $message = $this->t->trans('The payment was cancelled. Your order is saved: you can try again.', [], 'Modules.Wajub.Shop');
                break;
            case 'expired':
                $message = $this->t->trans('Your payment session expired before it was completed. Your order is saved: you can try again.', [], 'Modules.Wajub.Shop');
                break;
            case 'order_cancelled':
                $message = $this->t->trans('This order was cancelled before it was paid.', [], 'Modules.Wajub.Shop');
                break;
            default:
                $message = $this->t->trans('The payment was declined or failed. Please try again or choose another payment method.', [], 'Modules.Wajub.Shop');
        }

        $answer = ['status' => 'failed', 'reason' => $reason, 'message' => $message, 'redirect' => $this->storefront->confirmation($order)];
        if ($retry && $reason !== 'order_cancelled') {
            $answer['retry'] = true;
        } elseif ($reason !== 'order_cancelled' && $this->payable($order)) {
            // Part of it paid: never given back as a cart, its rest paid instead.
            $answer['pay'] = true;
        }

        return $answer;
    }
}
