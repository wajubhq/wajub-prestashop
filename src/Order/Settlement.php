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

use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Payment\ChannelLabels;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Payment\RefundRecord;
use Wajub\PrestaShop\Payment\RefundStore;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\MoneyFormatter;
use Wajub\PrestaShop\Support\Text;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What an order becomes once Wajub says where its payment stands: one place
 * for the webhook, the payer's return, the checkout's and the confirmation
 * page's status checks and the merchant's "Check status", so they all pay,
 * count and annotate an order the same way. A port of the WordPress
 * plugin's OrderSettlement.
 *
 * Every caller holds the order's lock (Support\Lock, `order:<id>`): the
 * webhook and the payer's return read the same payment at the same moment,
 * and only one of them counts it.
 */
final class Settlement
{
    /** apply() of a payment read right after it was paid: the payer's return, the status checks, its own webhook. */
    public const SEEN_NOW = 'now';

    /** apply() of a payment read later: an earlier attempt, "Check status", the cleanup of unpaid orders. */
    public const SEEN_LATER = 'later';

    /**
     * As SEEN_LATER, for a payment settled before a new one starts for the
     * order, whose own note says what is left to pay (Checkout\Starter).
     */
    public const SEEN_BEFORE_NEW = 'before';

    /**
     * As SEEN_BEFORE_NEW, for the answer Wajub gave as the shop closed the
     * payment keeping what it collected (Order\Instalments): it has given
     * nothing back.
     */
    public const SEEN_CLOSED = 'closed';

    /**
     * What apply() says of a payment that went through without paying the
     * order: counted toward it, with something left to pay, or in another
     * currency, which counts nothing.
     */
    public const OUTCOME_SHORT = 'short';

    /** Statuses in which the payer stopped, rather than a payment being refused. */
    private const ABANDONED = ['canceled', 'cancelled', 'abandoned', 'expired'];

    /** Seconds an answer is kept while the payment is under way: one API read per payment in that time, however many pages ask. */
    private const OUTCOME_TTL_PENDING = 10;

    /** Seconds a final answer is kept: it no longer changes. */
    private const OUTCOME_TTL_FINAL = 60;

    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var RefundStore */
    private $refunds;

    /** @var Ledger */
    private $ledger;

    /** @var callable(string): Client */
    private $clientFor;

    /** @var StateStore */
    private $state;

    /** @var Translator */
    private $t;

    /** @var MoneyFormatter */
    private $money;

    /** @var Logger */
    private $logger;

    /** @var int */
    private $partialState;

    /** @var string why the last refunds read that left a payment uncounted failed: 'unanswered' or 'refused' */
    private $refundsFailure = 'unanswered';

    /** @var RecountHint|null when the pages ask for the payments that could not count to be read again (Order\Recounts) */
    private $recountHint;

    /**
     * @param callable(string): Client $clientFor the shop's client of an environment ('sandbox' or 'live'), with that environment's key only
     */
    public function __construct(ShopOrders $orders, PaymentStore $payments, RefundStore $refunds, Ledger $ledger, callable $clientFor, StateStore $state, Translator $t, MoneyFormatter $money, Logger $logger, int $partialState, ?RecountHint $recountHint = null)
    {
        $this->orders = $orders;
        $this->payments = $payments;
        $this->refunds = $refunds;
        $this->ledger = $ledger;
        $this->clientFor = $clientFor;
        $this->state = $state;
        $this->t = $t;
        $this->money = $money;
        $this->logger = $logger;
        $this->partialState = $partialState;
        $this->recountHint = $recountHint;
    }

    /**
     * Applies a Client outcome (paymentOutcome(), or outcome() here) of one
     * of the order's payments, and says which it was.
     *
     * A payment that went through counts toward the order for what it
     * collected, in its currency and environment, and the order is paid once
     * what its payments hold there covers its total. Short of it, the order
     * is "Partially paid with Wajub", with a note of what is left to pay
     * (OUTCOME_SHORT); in another currency, it counts nothing, with a note
     * (OUTCOME_SHORT too).
     *
     * Before a payment counts for the first time, its refunds are read,
     * unless it is the order's own payment read right after it went through
     * in full, or one the shop has just closed keeping what it collected:
     * one counted late, closed early or read as refunded may have given
     * money back already. When they cannot be read, it does not count yet
     * (`uncounted`, read again later: Order\Cleanup), and the outcome is
     * pending: the order stays as it is. What a refund read now gave back
     * of an order never paid is noted: the payer pays it again.
     *
     * Short of the total, the note of what is left to pay is written unless
     * `$seen` is SEEN_BEFORE_NEW or SEEN_CLOSED: the new payment's own note
     * says it, and the payment's late webhook adds none.
     *
     * A payment the payer cancelled or let expire leaves the order as it
     * is, with a note. A refused one moves the order to "Payment error",
     * unless the order holds money already. A paid order is never failed,
     * and a pending outcome changes nothing. An order paid once (refunded,
     * or cancelled by the merchant since) is never paid, failed or counted
     * toward again: a payment it does not count is a second one. A payment Wajub holds for
     * another order changes nothing: OUTCOME_MISMATCH.
     *
     * @param array{outcome?: string, status?: string, transaction?: array<string, mixed>} $result
     */
    public function apply(OrderView $order, PaymentRecord $record, array $result, string $seen = self::SEEN_LATER): string
    {
        $outcome = (string) ($result['outcome'] ?? Client::OUTCOME_PENDING);
        $transaction = is_array($result['transaction'] ?? null) ? $result['transaction'] : [];
        $status = strtolower((string) ($result['status'] ?? ($transaction['status'] ?? '')));

        if ($record->idOrder !== $order->id || self::namesAnotherOrder($order, $transaction)) {
            return Client::OUTCOME_MISMATCH;
        }

        $this->remember($record, $transaction);

        if ($outcome === Client::OUTCOME_SUCCEEDED) {
            if ($order->paid) {
                return $outcome;
            }
            if ($transaction === []) {
                return Client::OUTCOME_PENDING;
            }
            if ($order->paidOnce) {
                // Paid once, then refunded or cancelled by the merchant: never
                // paid again from here, nor counted. A payment it did not
                // count charged the payer once more: the merchant is told.
                $this->noteSecondPayment($order, $record, $transaction);

                return $outcome;
            }
            if (!Ledger::sameCurrency($order, $transaction)) {
                $this->noteOtherCurrency($order, $record, $transaction);

                return self::OUTCOME_SHORT;
            }

            $environment = Ledger::environmentOf($transaction, $record->environment);
            $counted = $this->count($order, $record, $transaction, $this->needsRefundRead($record, $transaction, $seen));
            if ($counted === null) {
                return Client::OUTCOME_PENDING;
            }
            if (!$this->ledger->covers($order, $environment)) {
                // Short of the total: the rest to pay.
                if ($record->isCounted()) {
                    if ($order->stateId !== $this->partialState && $order->stateId !== $this->orders->canceledState()) {
                        $this->orders->setState($order, $this->partialState, false);
                    }
                    if (!in_array($seen, [self::SEEN_BEFORE_NEW, self::SEEN_CLOSED], true)) {
                        $this->noteLeftToPay($order, $record, $transaction, $environment);
                    } else {
                        // The new payment's note says what is left: the
                        // payment's own webhook, delivered late, adds none.
                        $this->orders->note($order, self::leftToPayKey($record->paymentId), '');
                    }
                }

                return self::OUTCOME_SHORT;
            }

            $this->complete($order, $record, $environment);

            return $outcome;
        }

        if ($outcome === Client::OUTCOME_FAILED) {
            // A paid order is never failed, nor one paid once (refunded
            // since, or set back by the merchant).
            if ($order->paid || $order->paidOnce) {
                return $outcome;
            }
            if (self::isAbandoned($status)) {
                $parameters = ['%payment%' => $record->paymentId, '%status%' => $status];
                // Cancelled by the shop itself, nobody having paid it (the
                // order given back as a cart, or left unpaid past the
                // delay): its webhook confirms it, the customer did nothing.
                $byShop = in_array($status, ['canceled', 'cancelled'], true) && ($record->metadata[PaymentRecord::CANCELLED_BY_SHOP] ?? '') !== '';
                $this->orders->note($order, 'abandoned_' . md5($record->paymentId . '|' . ($status === 'canceled' ? 'cancelled' : $status)), $byShop
                    ? $this->t->trans('Wajub payment %payment% was cancelled by the shop, before anyone paid it (Wajub status: %status%).', $parameters, 'Modules.Wajub.Admin')
                    : $this->t->trans('The customer did not complete Wajub payment %payment% (Wajub status: %status%).', $parameters, 'Modules.Wajub.Admin'));

                return $outcome;
            }

            $this->orders->note($order, 'failed_' . md5($record->paymentId), $this->failureNote($record->paymentId, $status, $transaction));
            // The order's own payment, and nothing Wajub took for it besides:
            // "Payment error". An order part of which is paid keeps its state,
            // and so does one cancelled already.
            $current = $this->payments->current($order->id);
            if ($current !== null && $current->paymentId === $record->paymentId && $order->stateId !== $this->orders->canceledState()
                && !$this->ledger->holdsMoney($order) && $this->ledger->uncounted($order) === []) {
                $this->orders->setState($order, $this->orders->errorState(), false);
            }

            return $outcome;
        }

        return $outcome;
    }

    /**
     * Counts toward the order a payment of it that went through, its refunds
     * read first, without paying the order: for the webhook of an earlier
     * attempt, which must know what that payment still holds before it
     * decides whether it pays the order. False when its refunds could not
     * be read; true otherwise, counted or not.
     *
     * @param array<string, mixed> $transaction
     */
    public function countLate(OrderView $order, PaymentRecord $record, array $transaction): bool
    {
        if ($order->paid || $order->paidOnce || !Ledger::sameCurrency($order, $transaction)) {
            return true;
        }

        return $this->count($order, $record, $transaction, true) !== null;
    }

    /**
     * Pays an unpaid order whose payments already cover it (the merchant
     * lowered its total after a payment short of it). True when it paid it.
     * Never one paid once (refunded, or cancelled by the merchant since).
     */
    public function completeIfCovered(OrderView $order, string $environment): bool
    {
        if ($order->paid || $order->paidOnce || !$this->ledger->covers($order, $environment)) {
            return false;
        }
        $current = $this->payments->current($order->id);
        if ($current === null) {
            return false;
        }
        $this->complete($order, $current, $environment);

        return true;
    }

    /**
     * Where a payment stands, shared by everyone who follows it: the order
     * confirmation page, the checkout's payment step and every tab a payer
     * left open ask every few seconds, and Wajub counts each read against
     * the same allowance as new payments. The answer is kept a few seconds
     * while the payment is under way, a minute once it is final; `$fresh`
     * reads Wajub whatever is kept. Read with the key of the payment's own
     * environment: a Sandbox payment is never read with the live key.
     *
     * A payment refunded since it went through (`partially_refunded`,
     * `refunded`) went through: it counts for what it still holds.
     *
     * A read Wajub did not answer says so (`unreachable`), and so does one
     * of a payment Wajub has not for the key of its environment (`missing`).
     *
     * @return array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true, missing?: true}
     */
    public function outcome(PaymentRecord $record, bool $fresh = false): array
    {
        $key = 'outcome:' . md5($record->paymentId);
        if (!$fresh) {
            $kept = $this->state->get($key);
            if (is_array($kept) && isset($kept['outcome'], $kept['status']) && is_array($kept['transaction'] ?? null)) {
                /* @var array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true, missing?: true} $kept */
                return $kept;
            }
        }

        $result = ($this->clientFor)($record->environment)->paymentOutcome($record->paymentId);
        $status = (string) $result['status'];
        $outcome = (string) $result['outcome'];
        if ($outcome === Client::OUTCOME_PENDING && Ledger::endedWithMoney($status)) {
            $outcome = Client::OUTCOME_SUCCEEDED;
        }
        $read = ['outcome' => $outcome, 'status' => $status, 'transaction' => $result['transaction']]
            + (!empty($result['unreachable']) ? ['unreachable' => true] : [])
            + (!empty($result['missing']) ? ['missing' => true] : []);
        $this->state->set($key, $read, $outcome === Client::OUTCOME_PENDING ? self::OUTCOME_TTL_PENDING : self::OUTCOME_TTL_FINAL);

        return $read;
    }

    /** Forgets what outcome() kept of a payment, once the module itself changed it (cancelled it). */
    public function forgetOutcome(PaymentRecord $record): void
    {
        $this->state->delete('outcome:' . md5($record->paymentId));
    }

    /**
     * Keeps on the payment's record what Wajub says of it: its status, what
     * it collected so far, how it was paid, and whether it ended.
     *
     * @param array<string, mixed> $transaction
     */
    public function remember(PaymentRecord $record, array $transaction): void
    {
        if ($transaction === [] || Ledger::idOf($transaction) !== $record->paymentId) {
            return;
        }

        $status = strtolower(Text::code($transaction['status'] ?? '', 32));
        $paymentMethod = is_array($transaction['payment_method'] ?? null) ? $transaction['payment_method'] : [];
        $channel = Text::code($transaction['channel'] ?? ($paymentMethod['channel'] ?? ''), 64);
        $paid = is_numeric($transaction['amount_paid'] ?? null) ? (float) $transaction['amount_paid'] : $record->amountPaid;

        $changed = false;
        if ($status !== '' && $status !== $record->status) {
            $record->status = $status;
            $changed = true;
        }
        if ($channel !== '' && $channel !== $record->channel) {
            $record->channel = $channel;
            $changed = true;
        }
        if (abs($paid - $record->amountPaid) > 0.0000001) {
            $record->amountPaid = $paid;
            $changed = true;
        }
        $final = Client::isSucceededStatus($status) || Client::isFailedStatus($status) || Ledger::endedWithMoney($status);
        if ($final && $record->state === PaymentRecord::ACTIVE) {
            $record->state = PaymentRecord::FINAL;
            $changed = true;
        }
        if ($changed) {
            $this->payments->save($record);
        }
    }

    /**
     * Tells the merchant, once per payment, that a payment of the order
     * went through though the order was paid already: the payer was charged
     * twice. An earlier attempt (its webhook, or a read again of one that
     * could not count), or the order's own payment once the others cover it
     * alone (an earlier attempt read again paid it first). Not the order's
     * own payment of an order marked paid by hand, nothing else covering
     * it, nor one refunded in full since: it gave everything back. The note
     * says what it collected, in its own currency, and to refund it; its
     * key is the same whoever finds it first. A payment that could not
     * count is no longer kept as such: it counts nothing toward a paid
     * order.
     *
     * @param array<string, mixed> $transaction
     */
    public function noteSecondPayment(OrderView $order, PaymentRecord $record, array $transaction): void
    {
        if ($transaction === [] || self::namesAnotherOrder($order, $transaction) || $this->ledger->isCounted($order, $record->paymentId)) {
            return;
        }
        $this->remember($record, $transaction);
        if ($record->state === PaymentRecord::UNCOUNTED) {
            $record->state = PaymentRecord::FINAL;
            $this->payments->save($record);
        }
        $current = $this->payments->current($order->id);
        $another = $current === null || $current->paymentId !== $record->paymentId
            || $this->ledger->covers($order, Ledger::environmentOf($transaction, $record->environment));
        if (!$another || strtolower((string) ($transaction['status'] ?? '')) === 'refunded') {
            return;
        }
        $currency = strtoupper(Text::code($transaction['currency'] ?? '', 3)) ?: $order->currency;
        $this->orders->note($order, 'second_' . md5($record->paymentId), $this->t->trans(
            'Wajub received a second payment for this order, already paid: %payment% (%amount%). If the customer did not mean to pay twice, refund it from the Wajub Dashboard.',
            ['%payment%' => $record->paymentId, '%amount%' => $this->money->format(Amount::collected($transaction), $currency)],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * Reads a payment of the order again, afresh, and, gone through, counts
     * it (apply()): one whose refunds could not be read before. Gone through
     * once the order was paid, it counts nothing, and may have charged the
     * payer twice (noteSecondPayment()). Another order's, or one Wajub does
     * not answer for, changes nothing.
     */
    public function readAgain(OrderView $order, PaymentRecord $record): void
    {
        $result = $this->outcome($record, true);
        if ($result['outcome'] !== Client::OUTCOME_SUCCEEDED) {
            return;
        }
        if ($order->paid) {
            $this->noteSecondPayment($order, $record, $result['transaction']);

            return;
        }
        $this->apply($order, $record, $result);
    }

    /**
     * Tells the merchant, once per payment, that a payment that went through
     * does not count toward the order yet, its refunds unread, and that the
     * order is kept as it is meanwhile: what it holds is not known, and may
     * be the order's whole total.
     */
    public function noteUncounted(OrderView $order, PaymentRecord $record): void
    {
        $this->orders->note($order, 'uncounted_' . md5($record->paymentId), $this->t->trans(
            'Wajub payment %payment% went through, but its refunds could not be read, so it does not count toward the order yet. The order stays as it is, and is not cancelled as unpaid, until the payment counts. Check the payment in the Wajub Dashboard.',
            ['%payment%' => $record->paymentId],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * Notes on an order, once per refund, that a payment counted toward it
     * has a refund that takes money back while the order had never been
     * paid (pending, under way or made): the payer pays that again, and
     * what is now left is said.
     */
    public function noteRefundOfPart(OrderView $order, PaymentRecord $record, RefundRecord $refund): void
    {
        if (!$refund->orderUnpaid || !$refund->takesMoney() || !$record->isCounted() || $refund->paymentId !== $record->paymentId) {
            return;
        }
        $this->orders->note($order, 'part_refund_' . md5($refund->refundId !== '' ? $refund->refundId : $refund->requestKey), $this->t->trans(
            'Wajub payment %payment%, which paid part of this order, has a refund of %amount%. %due% of the order is now left to pay.',
            [
                '%payment%' => $record->paymentId,
                '%amount%' => $this->money->format($refund->amount, $refund->currency !== '' ? $refund->currency : $order->currency),
                '%due%' => $this->money->format($this->ledger->due($order, $record->environment), $order->currency),
            ],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * Whether Wajub holds the payment for another order: the order its
     * metadata names is not this one. Such a payment neither pays this
     * order, fails it, nor is cancelled from it.
     *
     * @param array<string, mixed> $transaction
     */
    public static function namesAnotherOrder(OrderView $order, array $transaction): bool
    {
        $metadata = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : [];
        foreach (['order_id', 'source_id'] as $key) {
            $named = $metadata[$key] ?? null;
            if (is_scalar($named) && !is_bool($named) && (string) $named !== '' && !in_array((int) $named, $order->ids !== [] ? $order->ids : [$order->id], true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the payer stopped the payment (cancelled it, let it expire) rather than it being refused. */
    public static function isAbandoned(string $status): bool
    {
        return in_array(strtolower($status), self::ABANDONED, true);
    }

    /**
     * The note of a payment that did not go through, with the status Wajub
     * gave and the reason it gave. For the merchant only.
     *
     * @param array<string, mixed> $transaction
     */
    public function failureNote(string $paymentId, string $status, array $transaction = []): string
    {
        $status = Text::code($status, 32) ?: 'failed';
        $reason = Text::line($transaction['failure_message'] ?? $transaction['failure_reason'] ?? '', 200);
        if ($reason !== '') {
            return $this->t->trans(
                'Wajub payment %payment% did not go through (Wajub status: %status%). Reason: %reason%',
                ['%payment%' => $paymentId, '%status%' => $status, '%reason%' => $reason],
                'Modules.Wajub.Admin'
            );
        }

        return $this->t->trans(
            'Wajub payment %payment% did not go through (Wajub status: %status%).',
            ['%payment%' => $paymentId, '%status%' => $status],
            'Modules.Wajub.Admin'
        );
    }

    /**
     * Records a payment that went through on the order, its refunds read
     * first when `$readRefunds`: true when it was counted now, false when it
     * was already or has nothing to count, null when its refunds could not
     * be read (the payment is kept `uncounted`, and the order as it is).
     *
     * @param array<string, mixed> $transaction
     */
    private function count(OrderView $order, PaymentRecord $record, array $transaction, bool $readRefunds): ?bool
    {
        if ($record->isCounted()) {
            return false;
        }
        if ($readRefunds) {
            $read = $this->readRefunds($order, $record, (string) ($transaction['status'] ?? ''));
            if ($read === false) {
                // Kept until it counts: read again a few minutes from now
                // (Order\Recounts), and by the scheduled task (Order\Cleanup),
                // which tell the merchant should it still not count then.
                $changed = $record->state !== PaymentRecord::UNCOUNTED;
                $record->state = PaymentRecord::UNCOUNTED;
                $due = $this->recountHint !== null ? Recounts::schedule($record, time()) : 0;
                if ($changed || $due > 0) {
                    $this->payments->save($record);
                }
                // Saved first: a run of the pages' reads that sets the hint
                // meanwhile finds the payment (Recounts::refresh()).
                if ($due > 0 && $this->recountHint !== null) {
                    $this->recountHint->lower($due);
                }

                return null;
            }
            if ($read === null) {
                $record->state = PaymentRecord::FINAL;
                $this->payments->save($record);

                return false;
            }
        }

        $collected = Amount::normalize(Amount::collected($transaction), $order->currency);
        if ($collected <= 0) {
            return false;
        }

        $record->idOrderPayment = $this->orders->addPayment($order, $collected, $record->paymentId);
        $record->counted = $collected;
        $record->state = PaymentRecord::FINAL;
        // When Wajub made it: a refund takes back from the payment made
        // last first, whatever order the shop recorded them in (Ledger).
        $madeAt = PaymentRecord::timeOf($transaction['created_at'] ?? null);
        $record->setMeta(PaymentRecord::MADE_AT, $madeAt > 0 ? (string) $madeAt : '');
        $this->payments->save($record);
        $this->logger->info('Payment counted toward the order', ['order' => $order->id, 'payment' => $record->paymentId, 'amount' => $collected]);

        // What it gave back while the order was unpaid: the payer pays it again.
        foreach ($this->refunds->forOrder($order->id) as $refund) {
            if ($refund->paymentId === $record->paymentId) {
                $this->noteRefundOfPart($order, $record, $refund);
            }
        }

        return true;
    }

    /**
     * Notes the refunds Wajub made of a payment (`GET /payments/{id}/refunds`):
     * true when the payment counts, false when it cannot count yet (Wajub did
     * not answer, or the key may not read the refunds of a payment refunded
     * in part), null when it counts nothing (refunded in full, its refunds
     * refused to the key). A key that may not read refunds counts a payment
     * whose status says nothing was refunded.
     */
    private function readRefunds(OrderView $order, PaymentRecord $record, string $status): ?bool
    {
        try {
            $refunds = ($this->clientFor)($record->environment)->paymentRefunds($record->paymentId);
        } catch (ApiException $e) {
            if (Client::unanswered($e)) {
                $this->refundsFailure = 'unanswered';
                $this->logger->error('Refunds of a payment could not be read: not counted yet', ['order' => $order->id, 'payment' => $record->paymentId, 'error' => $e->getMessage()]);

                return false;
            }
            if (strtolower($status) === 'refunded') {
                return null;
            }
            if (Ledger::endedWithMoney($status) && !Client::isSucceededStatus($status)) {
                $this->refundsFailure = 'refused';
                $this->orders->note($order, 'refunds_refused_' . md5($record->paymentId), $this->t->trans(
                    'Wajub payment %payment% was partly refunded, and this shop\'s key may not read its refunds (refund.read): it does not count toward the order until the key may. Connect with Wajub again, or give the key refund.read in the Wajub Dashboard.',
                    ['%payment%' => $record->paymentId],
                    'Modules.Wajub.Admin'
                ));

                return false;
            }
            $this->logger->info('Refunds of a payment refused to this key: counted without them', ['order' => $order->id, 'payment' => $record->paymentId, 'status' => $e->httpStatus]);

            return true;
        } catch (\Throwable $e) {
            $this->refundsFailure = 'unanswered';
            $this->logger->error('Refunds of a payment could not be read: not counted yet', ['order' => $order->id, 'payment' => $record->paymentId, 'error' => $e->getMessage()]);

            return false;
        }

        foreach ($refunds as $refund) {
            $this->refunds->note($record, $refund, RefundRecord::ORIGIN_WAJUB, !$order->paidOnce);
        }

        return true;
    }

    /** Why the last payment left uncounted was: 'unanswered' or 'refused'. */
    public function refundsFailure(): string
    {
        return $this->refundsFailure;
    }

    /**
     * Whether a payment's refunds must be read before it counts: always,
     * but for the order's own payment read right after it went through in
     * full, which has given nothing back yet.
     *
     * @param array<string, mixed> $transaction
     */
    private function needsRefundRead(PaymentRecord $record, array $transaction, string $seen): bool
    {
        if ($seen === self::SEEN_CLOSED) {
            return false;
        }
        if ($seen !== self::SEEN_NOW || !Client::isSucceededStatus((string) ($transaction['status'] ?? ''))) {
            return true;
        }
        if (!is_numeric($transaction['amount'] ?? null) || Amount::collected($transaction) + 0.000001 < (float) $transaction['amount']) {
            return true;
        }
        $current = $this->payments->current($record->idOrder);

        return $current === null || $current->paymentId !== $record->paymentId;
    }

    /**
     * The order is paid: its payments cover it. The note names how it was
     * paid and, when several payments paid it, which ones; Wajub holding more
     * than the order's total is noted too.
     */
    private function complete(OrderView $order, PaymentRecord $record, string $environment): void
    {
        if ($order->stateId === $this->orders->canceledState()) {
            // Paid after all once the payer had left it (given back as a
            // cart), or once the merchant cancelled it: the customer may have
            // paid the same products twice.
            $this->orders->note($order, 'paid_cancelled', $this->t->trans(
                'This order had been cancelled when Wajub payment %payment% paid it: it is paid now. Check that the customer did not place and pay the same order again; refund one from the Wajub Dashboard if they did.',
                ['%payment%' => $record->paymentId],
                'Modules.Wajub.Admin'
            ));
        }
        $this->orders->setState($order, $this->orders->paidState(), true);

        $channel = ChannelLabels::label($record->channel, $this->t);
        $this->orders->note($order, 'paid', $channel !== ''
            ? $this->t->trans('Wajub confirmed the payment (%method%).', ['%method%' => $channel], 'Modules.Wajub.Admin')
            : $this->t->trans('Wajub confirmed the payment.', [], 'Modules.Wajub.Admin'));

        $ids = array_keys($this->ledger->counted($order, $environment));
        if (count($ids) > 1) {
            $this->orders->note($order, 'paid_by_several', $this->t->trans(
                'The order is paid in full by Wajub payments %payments%.',
                ['%payments%' => implode(', ', $ids)],
                'Modules.Wajub.Admin'
            ));
        }

        $held = $this->ledger->held($order, $environment);
        $total = Ledger::total($order);
        if ($held - $total > 0.000001) {
            $this->orders->note($order, 'surplus', $this->t->trans(
                'Wajub holds %held% for this order, %more% more than its total of %total%. Refund the difference from the Wajub Dashboard if the customer should get it back.',
                [
                    '%held%' => $this->money->format($held, $order->currency),
                    '%more%' => $this->money->format($held - $total, $order->currency),
                    '%total%' => $this->money->format($total, $order->currency),
                ],
                'Modules.Wajub.Admin'
            ));
        }
    }

    /**
     * The note of a payment counted toward an order it does not pay in full,
     * once per payment: what it collected, what Wajub holds for the order,
     * and what is left to pay.
     *
     * @param array<string, mixed> $transaction
     */
    private function noteLeftToPay(OrderView $order, PaymentRecord $record, array $transaction, string $environment): void
    {
        $parameters = [
            '%payment%' => $record->paymentId,
            '%collected%' => $this->money->format(Amount::collected($transaction), $order->currency),
            '%held%' => $this->money->format($this->ledger->held($order, $environment), $order->currency),
            '%due%' => $this->money->format($this->ledger->due($order, $environment), $order->currency),
            '%total%' => $this->money->format(Ledger::total($order), $order->currency),
        ];
        // For an order the customer can still pay from its pages (a Wajub
        // order neither cancelled nor paid once: Checkout\StatusAnswer::payable()),
        // how they pay the rest; never a way the order's pages do not offer.
        $payable = $order->isWajub() && !$order->paidOnce && $order->stateId !== $this->orders->canceledState();
        $this->orders->note($order, self::leftToPayKey($record->paymentId), !$payable
            ? $this->t->trans('Wajub payment %payment% collected %collected%. Wajub holds %held% for this order: %due% of its total of %total% is left to pay.', $parameters, 'Modules.Wajub.Admin')
            : $this->t->trans('Wajub payment %payment% collected %collected%. Wajub holds %held% for this order: %due% of its total of %total% is left to pay. The customer pays it with "Pay the rest", on the order in their account or on its confirmation page.', $parameters, 'Modules.Wajub.Admin'));
    }

    /** The key of a payment's note of what is left to pay, whoever writes it, or marks it said. */
    private static function leftToPayKey(string $paymentId): string
    {
        return 'left_' . md5($paymentId);
    }

    /**
     * A payment that went through in another currency than the order's
     * counts nothing toward it: the note says what it collected, in its own
     * currency, and that the merchant refunds it.
     *
     * @param array<string, mixed> $transaction
     */
    private function noteOtherCurrency(OrderView $order, PaymentRecord $record, array $transaction): void
    {
        $currency = strtoupper(Text::code($transaction['currency'] ?? '', 3));
        if ($record->state === PaymentRecord::ACTIVE) {
            $record->state = PaymentRecord::FINAL;
            $this->payments->save($record);
        }
        $this->orders->note($order, 'currency_' . md5($record->paymentId), $this->t->trans(
            'Wajub payment %payment% collected %collected%, in another currency than this order\'s (%currency%): it does not count toward the order. Refund it from the Wajub Dashboard.',
            [
                '%payment%' => $record->paymentId,
                '%collected%' => $this->money->format(Amount::collected($transaction), $currency !== '' ? $currency : $order->currency),
                '%currency%' => $order->currency,
            ],
            'Modules.Wajub.Admin'
        ));
    }
}
