<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Admin;

use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Payment\ChannelLabels;
use Wajub\PrestaShop\Payment\MethodTexts;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\RefundRecord;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The "Wajub payment" panel of the back office's order page
 * (hookDisplayAdminOrderMain): each Wajub payment of the order (its id,
 * environment, how it was paid, what it asked, collected and counted
 * toward the order, what was refunded of it, its status, a link to it in
 * the Wajub Dashboard), its refunds, what the order received and what is
 * left to pay while it is unpaid, the module's notes on the order, and
 * "Check status", which reads the order's payment from Wajub and settles the
 * order as its webhook would, saying why it is where it is: for an order
 * still waiting for its payment only. A port of the
 * WordPress plugin's OrderMetaBox. Read with the services of the order's
 * shop; the page itself never waits on Wajub: what is read from Wajub is
 * read once the page has loaded (refresh(), "Check status").
 */
final class OrderPanel
{
    public const DASHBOARD = 'https://dashboard.wajub.com';

    /** Seconds "Check status" waits for a webhook or a payer's return to finish with the order. */
    private const LOCK_WAIT = 5;

    /** Seconds between two reads of an order's payments as its page opens. */
    private const REFRESH_EVERY = 60;

    /** @var Services */
    private $services;

    public function __construct(Services $services)
    {
        $this->services = $services;
    }

    /**
     * What the panel's template shows, or null for an order with no Wajub
     * payment (placed with another module, or before its payment started).
     *
     * @return array<string, mixed>|null
     */
    public function view(int $idOrder): ?array
    {
        // One of the orders a cart that ships in several packages became:
        // the group's payments, refunds and notes, under its first order.
        $order = $this->services->orders()->find($idOrder);
        $records = $order !== null ? $this->services->payments()->forOrder($order->id) : [];
        if ($order === null || ($records === [] && !$order->isWajub())) {
            return null;
        }

        $t = $this->services->translator();
        $money = $this->services->money();
        $ledger = $this->services->ledger();
        $environment = $this->services->environment()->isLive() ? 'live' : 'sandbox';
        $refunds = $this->services->refunds()->forOrder($order->id);
        $current = $records === [] ? null : $records[count($records) - 1];
        $payments = [];
        foreach (array_reverse($records) as $record) {
            $channel = ChannelLabels::label($record->channel, $t);
            $method = MethodTexts::defaultTitle($record->method, $t);
            $refunded = $ledger->refunded($order, $record->paymentId);
            $openPlan = $current !== null && $record->paymentId === $current->paymentId && !$order->paid
                && Ledger::openWithMoney(['status' => $record->status, 'amount' => $record->amount, 'amount_paid' => $record->amountPaid]);
            $payments[] = [
                'id' => $record->paymentId,
                'environment' => $record->environment,
                'environment_label' => self::environmentLabel($record->environment, $t),
                'method' => $channel !== '' ? $method . ' · ' . $channel : $method,
                'mode' => $record->mode === PaymentRecord::MODE_FIELDS
                    ? $t->trans('Payment form in the checkout', [], 'Modules.Wajub.Admin')
                    : $t->trans('Wajub payment page', [], 'Modules.Wajub.Admin'),
                'status' => $record->status,
                'status_label' => $openPlan ? $t->trans('Paying in instalments', [], 'Modules.Wajub.Admin') : self::statusLabel($record->status, $t),
                'amount' => $money->format($record->amount, $record->currency),
                'paid' => $record->amountPaid > 0 && abs($record->amountPaid - $record->amount) >= 0.01
                    ? $money->format($record->amountPaid, $record->currency)
                    : '',
                // More than asked: the fee the payer bore. Less: a plan
                // closed early, or still being paid.
                'paid_more' => $record->amountPaid - $record->amount >= 0.01,
                'counted' => $record->isCounted() ? $money->format($record->counted, $record->currency) : '',
                // Counted, in an environment or a currency that counts nothing now.
                'not_counted' => $record->isCounted() && ($record->environment !== $environment || $record->currency !== $order->currency),
                'refunded' => $refunded > 0 ? $money->format($refunded, $order->currency) : '',
                'uncounted' => $record->state === PaymentRecord::UNCOUNTED,
                'reference' => $record->reference,
                'url' => self::DASHBOARD . '/payments/' . rawurlencode($record->paymentId),
            ];
        }

        $held = $ledger->held($order, $environment);

        return [
            'order_id' => $order->id,
            // One of the orders a cart that ships in several packages
            // became: PrestaShop shows each order's own total beside the
            // panel, whose amounts are those of them all.
            'group' => count($order->ids) > 1
                ? $t->trans('Orders %orders% (reference %reference%) are one cart shipped in several packages: the payments, amounts and notes here are those of them all.', ['%orders%' => implode(', ', $order->ids), '%reference%' => $order->reference], 'Modules.Wajub.Admin')
                : '',
            'payments' => $payments,
            'refunds' => array_map(static function (RefundRecord $refund) use ($t, $money): array {
                return [
                    'id' => $refund->refundId !== '' ? $refund->refundId : '—',
                    'payment' => $refund->paymentId,
                    'amount' => $money->format($refund->amount, $refund->currency),
                    'status' => $refund->status,
                    'status_label' => self::refundStatusLabel($refund->status, $t),
                    'origin' => $refund->isShops()
                        ? $t->trans('Asked from this order', [], 'Modules.Wajub.Admin')
                        : $t->trans('Made in the Wajub Dashboard', [], 'Modules.Wajub.Admin'),
                    'slip' => $refund->idOrderSlip,
                ];
            }, array_reverse($refunds)),
            'received' => !$order->paid && $held > 0
                ? $t->trans('%received% of %total%', ['%received%' => $money->format($held, $order->currency), '%total%' => $money->format(Ledger::total($order), $order->currency)], 'Modules.Wajub.Admin')
                : '',
            'left' => !$order->paid && $held > 0 ? $money->format($ledger->due($order, $environment), $order->currency) : '',
            'refresh' => $ledger->uncounted($order) !== [],
            'checkable' => $this->checkable($order),
            'notes' => $this->services->orders()->notes($order->id),
        ];
    }

    /**
     * Whether "Check status" is offered: a Wajub order waiting for its
     * payment, paid in part, or in "Payment error", as the plugin offers it
     * for pending, on-hold and failed orders. Never one paid, cancelled or
     * refunded: reading its payment again would pay it again.
     */
    private function checkable(OrderView $order): bool
    {
        $settings = $this->services->settings();

        return $order->isWajub() && !$order->paid && !$order->paidOnce
            && in_array($order->stateId, [$settings->awaitingState(), $settings->partialState(), $this->services->orders()->errorState()], true);
    }

    /**
     * Reads again, once the order's page has loaded, the order's payments
     * that went through but could not count yet (their refunds unread), as
     * the scheduled task does (Order\Cleanup): counted now if they can be;
     * still unread, the merchant is told, once. At most once a minute per
     * order, and never while another request settles it. `changed`: the
     * page shows the order as it was.
     *
     * @return array{changed: bool, message: string}
     */
    public function refresh(int $idOrder): array
    {
        $none = ['changed' => false, 'message' => ''];
        $idOrder = $this->services->orders()->leadId($idOrder);
        if (!$this->services->state()->add('refreshed:' . $idOrder, 1, self::REFRESH_EVERY)) {
            return $none;
        }
        $lock = $this->services->lock();
        $name = 'order:' . $idOrder;
        if (!$lock->acquire($name, 0)) {
            return $none;
        }
        try {
            $order = $this->services->orders()->find($idOrder);
            if ($order === null) {
                return $none;
            }
            $ledger = $this->services->ledger();
            $settlement = $this->services->settlement();
            $before = $this->fingerprint($order);
            foreach ($ledger->uncounted($order) as $record) {
                $settlement->readAgain($order, $record);
                $order = $this->services->orders()->find($order->id) ?? $order;
            }
            if (!$order->paid) {
                foreach ($ledger->uncounted($order) as $record) {
                    $settlement->noteUncounted($order, $record);
                }
            }
            $changed = $this->fingerprint($order) !== $before;

            return ['changed' => $changed, 'message' => $changed ? $this->services->translator()->trans('Wajub was asked again about this order\'s payments: the order is updated.', [], 'Modules.Wajub.Admin') : ''];
        } finally {
            $lock->release($name);
        }
    }

    /**
     * "Check status": the order's payment read from Wajub, afresh, and the
     * order settled with it as its webhook would settle it, with why it is
     * where it is now. `tone` success, warning or error; `reload` when the
     * order changed, so the page shows it.
     *
     * @return array{ok: bool, message: string, tone: string, reload: bool}
     */
    public function check(int $idOrder): array
    {
        $t = $this->services->translator();
        $lock = $this->services->lock();
        $name = 'order:' . $this->services->orders()->leadId($idOrder);
        if (!$lock->acquire($name, self::LOCK_WAIT)) {
            return self::answer(false, $t->trans('The order is being updated. Try again in a moment.', [], 'Modules.Wajub.Admin'), 'warning', false);
        }
        try {
            return $this->checkLocked($idOrder);
        } finally {
            $lock->release($name);
        }
    }

    /**
     * @return array{ok: bool, message: string, tone: string, reload: bool}
     */
    private function checkLocked(int $idOrder): array
    {
        $t = $this->services->translator();
        $money = $this->services->money();
        $orders = $this->services->orders();
        $ledger = $this->services->ledger();
        $settlement = $this->services->settlement();
        $unreachable = $t->trans('Wajub could not be reached, or has no answer for this payment yet. Check again in a moment.', [], 'Modules.Wajub.Admin');

        $order = $orders->find($idOrder);
        $current = $order !== null ? $this->services->payments()->current($order->id) : null;
        if ($order === null || ($current === null && !$order->isWajub())) {
            return self::answer(false, $t->trans('This order has no Wajub payment.', [], 'Modules.Wajub.Admin'), 'warning', false);
        }
        if ($order->paid) {
            return self::answer(true, $t->trans('This order is already paid.', [], 'Modules.Wajub.Admin'), 'success', false);
        }
        if (!$order->isWajub()) {
            return self::answer(true, $t->trans('This order is paid with another payment method: there is no Wajub payment to check.', [], 'Modules.Wajub.Admin'), 'warning', false);
        }
        if (!$this->checkable($order)) {
            return self::answer(true, $t->trans('This order no longer waits for its payment: there is nothing to check with Wajub.', [], 'Modules.Wajub.Admin'), 'warning', false);
        }
        $environment = $this->services->environment()->isLive() ? 'live' : 'sandbox';
        // Its payments cover it already: the merchant lowered its total.
        if ($settlement->completeIfCovered($order, $environment)) {
            return self::answer(true, $t->trans('Wajub confirmed the payment: the order is now marked as paid.', [], 'Modules.Wajub.Admin'), 'success', true);
        }
        if ($current === null) {
            return self::answer(false, $t->trans('This order has no Wajub payment.', [], 'Modules.Wajub.Admin'), 'warning', false);
        }

        $read = $settlement->outcome($current, true);
        $settlement->forgetOutcome($current);
        if (!empty($read['missing'])) {
            return self::answer(true, $t->trans('Wajub has no payment %payment% in %environment% for this shop\'s key: it was made with another Wajub account, or in the other environment. The order is left as it is.', [
                '%payment%' => $current->paymentId,
                '%environment%' => self::environmentLabel($current->environment, $t),
            ], 'Modules.Wajub.Admin'), 'warning', false);
        }
        if (!empty($read['unreachable'])) {
            return self::answer(true, $unreachable, 'warning', false);
        }

        $transaction = $read['transaction'];
        $status = strtolower($read['status']);
        if ($read['outcome'] === Client::OUTCOME_PENDING) {
            $settlement->remember($current, $transaction);
            $applied = Client::OUTCOME_PENDING;
        } else {
            $applied = $settlement->apply($order, $current, $read, Settlement::SEEN_LATER);
        }
        $order = $orders->find($order->id) ?? $order;
        $paymentCurrency = strtoupper((string) ($transaction['currency'] ?? '')) ?: $order->currency;

        if ($applied === Client::OUTCOME_MISMATCH) {
            return self::answer(true, $t->trans('This Wajub payment belongs to another order: this order is left as it is.', [], 'Modules.Wajub.Admin'), 'warning', false);
        }
        if ($applied === Client::OUTCOME_SUCCEEDED && $order->paid) {
            return self::answer(true, $t->trans('Wajub confirmed the payment: the order is now marked as paid.', [], 'Modules.Wajub.Admin'), 'success', true);
        }
        if ($applied === Settlement::OUTCOME_SHORT && $ledger->isCounted($order, $current->paymentId)) {
            $counted = Ledger::environmentOf($transaction, $current->environment);

            return self::answer(true, $t->trans('Wajub confirmed this payment: %received% received so far, %left% left to pay. The customer can pay the rest from the order in their account, or from its confirmation page.', [
                '%received%' => $money->format($ledger->held($order, $counted), $order->currency),
                '%left%' => $money->format($ledger->due($order, $counted), $order->currency),
            ], 'Modules.Wajub.Admin'), 'warning', true);
        }
        if ($applied === Settlement::OUTCOME_SHORT && trim((string) ($transaction['currency'] ?? '')) !== '' && !Ledger::sameCurrency($order, $transaction)) {
            return self::answer(true, $t->trans('Wajub confirmed this payment, but in another currency than the order\'s: it does not count toward the order, which is left unpaid. Refund it from the Wajub Dashboard.', [], 'Modules.Wajub.Admin'), 'warning', true);
        }
        if ($applied === Settlement::OUTCOME_SHORT) {
            return self::answer(true, $t->trans('Wajub confirmed this payment, but it does not cover the order\'s total: the order is left unpaid. Check the payment in the Wajub Dashboard.', [], 'Modules.Wajub.Admin'), 'warning', true);
        }
        if ($applied === Client::OUTCOME_PENDING && Ledger::openWithMoney($transaction)) {
            return self::answer(true, $t->trans('The customer is paying in instalments: %paid% of %amount% received so far. The order is paid with the last instalment.', [
                '%paid%' => $money->format(Ledger::paidSoFar($transaction), $paymentCurrency),
                '%amount%' => $money->format(is_numeric($transaction['amount'] ?? null) ? (float) $transaction['amount'] : 0.0, $paymentCurrency),
            ], 'Modules.Wajub.Admin'), 'warning', false);
        }
        if ($applied === Client::OUTCOME_PENDING && $read['outcome'] === Client::OUTCOME_SUCCEEDED) {
            $record = $this->services->payments()->find($current->paymentId);
            if ($record !== null && $record->state === PaymentRecord::UNCOUNTED && $settlement->refundsFailure() === 'refused') {
                // Refunded in part, its refunds refused to this key (403):
                // asking again changes nothing until the key may read them.
                return self::answer(true, $t->trans('Wajub confirmed this payment, but it was partly refunded and this shop\'s key may not read its refunds (refund.read): it does not count toward the order until the key may. Connect with Wajub again, or give the key refund.read in the Wajub Dashboard.', [], 'Modules.Wajub.Admin'), 'warning', true);
            }

            return self::answer(true, $unreachable, 'warning', false);
        }
        if ($applied === Client::OUTCOME_FAILED && Settlement::isAbandoned($status)) {
            return self::answer(true, $t->trans('The customer did not complete the payment (Wajub status: %status%). The order stays as it is: the customer can still pay it.', ['%status%' => $status], 'Modules.Wajub.Admin'), 'warning', true);
        }
        if ($applied === Client::OUTCOME_FAILED) {
            return self::answer(true, $order->stateId === $orders->errorState()
                ? $t->trans('The payment did not go through (Wajub status: %status%). The order is marked as "Payment error".', ['%status%' => $status], 'Modules.Wajub.Admin')
                : $t->trans('The payment did not go through (Wajub status: %status%).', ['%status%' => $status], 'Modules.Wajub.Admin'), 'error', true);
        }
        if ($status !== '') {
            return self::answer(true, $t->trans('Wajub has not confirmed this payment yet (Wajub status: %status%). Check again in a moment.', ['%status%' => $status], 'Modules.Wajub.Admin'), 'warning', false);
        }

        return self::answer(true, $unreachable, 'warning', false);
    }

    /** A payment's status as the merchant reads it. */
    public static function statusLabel(string $status, Translator $t): string
    {
        switch (strtolower($status)) {
            case 'succeeded':
            case 'complete':
            case 'paid':
                return $t->trans('Succeeded', [], 'Modules.Wajub.Admin');
            case 'processing':
                return $t->trans('Processing', [], 'Modules.Wajub.Admin');
            case 'partial':
                return $t->trans('Paying in instalments', [], 'Modules.Wajub.Admin');
            case 'failed':
            case 'rejected':
                return $t->trans('Failed', [], 'Modules.Wajub.Admin');
            case 'canceled':
            case 'cancelled':
            case 'abandoned':
            case 'expired':
                return $t->trans('Cancelled', [], 'Modules.Wajub.Admin');
            case 'refunded':
                return $t->trans('Refunded', [], 'Modules.Wajub.Admin');
            case 'partially_refunded':
            case 'partially-refunded':
                return $t->trans('Partially refunded', [], 'Modules.Wajub.Admin');
            default:
                return $t->trans('Pending', [], 'Modules.Wajub.Admin');
        }
    }

    /** A refund's status as the merchant reads it. */
    public static function refundStatusLabel(string $status, Translator $t): string
    {
        switch ($status) {
            case 'succeeded':
                return $t->trans('Refunded', [], 'Modules.Wajub.Admin');
            case 'processing':
                return $t->trans('Processing', [], 'Modules.Wajub.Admin');
            case 'failed':
                return $t->trans('Failed', [], 'Modules.Wajub.Admin');
            case 'cancelled':
                return $t->trans('Cancelled', [], 'Modules.Wajub.Admin');
            case RefundRecord::REQUESTED:
                return $t->trans('Not confirmed by Wajub yet', [], 'Modules.Wajub.Admin');
            default:
                return $t->trans('Pending', [], 'Modules.Wajub.Admin');
        }
    }

    public static function environmentLabel(string $environment, Translator $t): string
    {
        return $environment === 'live'
            ? $t->trans('Live', [], 'Modules.Wajub.Admin')
            : $t->trans('Sandbox', [], 'Modules.Wajub.Admin');
    }

    /** What the order shows of its payments, to tell whether a read changed it. */
    private function fingerprint(OrderView $order): string
    {
        $order = $this->services->orders()->find($order->id) ?? $order;
        $parts = [(string) $order->stateId, (string) count($this->services->orders()->notes($order->id))];
        foreach ($this->services->payments()->forOrder($order->id) as $record) {
            $parts[] = $record->paymentId . ':' . $record->state . ':' . $record->counted;
        }

        return implode('|', $parts);
    }

    /**
     * @return array{ok: bool, message: string, tone: string, reload: bool}
     */
    private static function answer(bool $ok, string $message, string $tone, bool $reload): array
    {
        return ['ok' => $ok, 'message' => $message, 'tone' => $tone, 'reload' => $reload];
    }
}
