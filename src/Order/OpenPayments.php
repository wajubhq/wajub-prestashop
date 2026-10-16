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
use Wajub\PrestaShop\Api\NotFoundException;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The payments of an order that may still be charged, settled before
 * anything else happens to the order: before another payment starts for it
 * (Checkout\Starter), and before it is given back as a cart
 * (Checkout\Release). A port of the WordPress plugin's
 * Gateway::settleBeforeNewPayment(), for every payment the order holds
 * rather than its last one only: a checkout left open in a second tab
 * places the same cart with another method while the first payment is on
 * its way.
 *
 * Each payment still open (`active`, not counted) is read afresh, with the
 * key of its own environment, then:
 * - went through: counted toward the order (Settlement), which may pay it;
 * - Wajub is taking it (an approval on the payer's phone, an instalment
 *   with the payer) or it is paid in instalments and still open: it goes
 *   on (WAIT);
 * - nobody paid it yet: cancelled with Wajub, so it can never be approved
 *   once the payer moved on (when the caller may cancel it; WAIT
 *   otherwise); Wajub refusing that means it went on meanwhile, and a read
 *   says how;
 * - Wajub no longer has it (404): nothing can charge it any more;
 * - ended unpaid: noted (Settlement);
 * - not read (no answer, a key Wajub refuses): it may still be charged, and
 *   nothing changes (UNCHECKED).
 * A payment Wajub holds for another order is left alone. One that went
 * through without counting yet (its refunds unread) is read again, and
 * counted if it can be now; until then it keeps the order WAIT. The order's
 * payment of the moment is read first, then the others, oldest first: once
 * one of them has paid the order, each other that went through charged the
 * payer twice, and the merchant is told (Settlement::noteSecondPayment()),
 * the order's own one too when it was read just before, uncounted.
 *
 * The caller holds the order's lock.
 */
final class OpenPayments
{
    /** No payment of the order can be charged any more: the order is free to move on. */
    public const CLEAR = 'clear';

    /** The order is paid. */
    public const PAID = 'paid';

    /** A payment may still be charged, or went through without counting yet: the order's confirmation page follows it. */
    public const WAIT = 'wait';

    /** Wajub could not say where a payment stands: nothing changed, and it may still be charged. */
    public const UNCHECKED = 'unchecked';

    /** The status one() gives a payment it cancelled itself. */
    private const CANCELLED_HERE = 'cancelled:here';

    /** Which verdict wins when the order's payments say different things. */
    private const RANK = [self::CLEAR => 0, self::UNCHECKED => 1, self::WAIT => 2, self::PAID => 3];

    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var Settlement */
    private $settlement;

    /** @var Ledger */
    private $ledger;

    /** @var callable(string): Client */
    private $clientFor;

    /** @var Logger */
    private $logger;

    /**
     * @param callable(string): Client $clientFor the shop's client of an environment, with its key only
     */
    public function __construct(ShopOrders $orders, PaymentStore $payments, Settlement $settlement, Ledger $ledger, callable $clientFor, Logger $logger)
    {
        $this->orders = $orders;
        $this->payments = $payments;
        $this->settlement = $settlement;
        $this->ledger = $ledger;
        $this->clientFor = $clientFor;
        $this->logger = $logger;
    }

    /**
     * Settles each payment of the order still open, and says where the
     * order stands (`verdict`), what each payment it looked at ended as
     * (`statuses`, Wajub's status by payment id, 'cancelled' for one it
     * cancelled) and which it cancelled (`cancelled`).
     *
     * `$seen`: Settlement::SEEN_NOW while the payer is at the payment (they
     * leave it), SEEN_LATER before a new one. `$reads`: what Wajub said of
     * some of them a moment ago, by payment id (the payer's return reads the
     * order's payment first). `$cancel`: whether a payment nobody paid yet
     * is cancelled; one left as it is keeps the order WAIT. `$leaving`: the
     * payment the payer leaves for another method, cancelled even while
     * Wajub is approving it (a card at its 3-D Secure challenge, a phone yet
     * to confirm), so that it can no longer go through and the payer may
     * pay another way; any other payment being approved is left to finish.
     *
     * @param array<string, array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true}> $reads
     *
     * @return array{verdict: string, statuses: array<string, string>, cancelled: list<string>}
     */
    public function settle(OrderView $order, string $seen, array $reads = [], bool $cancel = true, string $leaving = ''): array
    {
        $verdict = self::CLEAR;
        $statuses = [];
        $cancelled = [];
        $read = [];
        foreach ($this->toSettle($order) as $record) {
            $read[$record->paymentId] = $reads[$record->paymentId] ?? $this->settlement->outcome($record, true);
            [$one, $status] = $record->state === PaymentRecord::UNCOUNTED
                ? $this->uncounted($order, $record, $read[$record->paymentId])
                : $this->one($order, $record, $read[$record->paymentId], $seen, $cancel, $leaving !== '' && $record->paymentId === $leaving);
            if ($status === self::CANCELLED_HERE) {
                $cancelled[] = $record->paymentId;
                $status = 'cancelled';
            }
            $statuses[$record->paymentId] = $status;
            if (self::RANK[$one] > self::RANK[$verdict]) {
                $verdict = $one;
            }
            // Counting a payment may have paid the order, or moved it on.
            $order = $this->orders->find($order->id) ?? $order;
        }

        if ($order->paid) {
            // Read before another of them paid the order, and left uncounted
            // then (its refunds unread): it charged the payer twice.
            foreach ($read as $paymentId => $answer) {
                $record = $this->payments->find((string) $paymentId);
                if ($record !== null && $record->state === PaymentRecord::UNCOUNTED && $answer['outcome'] === Client::OUTCOME_SUCCEEDED) {
                    $this->settlement->noteSecondPayment($order, $record, $answer['transaction']);
                }
            }

            return ['verdict' => self::PAID, 'statuses' => $statuses, 'cancelled' => $cancelled];
        }
        // Went through, but cannot count yet: the payer was charged.
        if ($verdict === self::CLEAR && $this->ledger->uncounted($order) !== []) {
            $verdict = self::WAIT;
        }

        return ['verdict' => $verdict, 'statuses' => $statuses, 'cancelled' => $cancelled];
    }

    /**
     * The merchant cancelled the order: each of its payments still open is
     * cancelled with Wajub, so that nobody pays a cancelled order, one being
     * approved included. Wajub refuses one that went through or collected
     * instalments, and may not answer:
     * those are left as they are, their webhook says what became of them.
     * Never moves the order: the merchant's state stands. The payments
     * cancelled, those Wajub refused to cancel, and those it did not answer
     * for.
     *
     * @return array{cancelled: list<string>, refused: list<string>, unanswered: list<string>}
     */
    public function cancelWithOrder(OrderView $order): array
    {
        $done = ['cancelled' => [], 'refused' => [], 'unanswered' => []];
        foreach ($this->toSettle($order) as $record) {
            if ($record->state !== PaymentRecord::ACTIVE) {
                continue;
            }
            try {
                ($this->clientFor)($record->environment)->cancelPayment($record->paymentId);
            } catch (NotFoundException $e) {
                // Wajub no longer has it: nothing can charge it.
                $record->state = PaymentRecord::FINAL;
                $this->payments->save($record);
                $this->settlement->forgetOutcome($record);

                continue;
            } catch (\Throwable $e) {
                $done[$e instanceof ApiException && !Client::unanswered($e) ? 'refused' : 'unanswered'][] = $record->paymentId;
                $this->logger->info('Open payment of an order the merchant cancelled left as it is', ['order' => $order->id, 'payment' => $record->paymentId, 'error' => $e->getMessage()]);

                continue;
            }
            $record->status = 'cancelled';
            $record->state = PaymentRecord::FINAL;
            // Its webhook, which follows, says the shop cancelled it.
            $record->metadata[PaymentRecord::CANCELLED_BY_SHOP] = (string) time();
            $this->payments->save($record);
            $this->settlement->forgetOutcome($record);
            $done['cancelled'][] = $record->paymentId;
        }

        return $done;
    }

    /**
     * The order's payments to settle, the one of the moment first, then the
     * others, oldest first: those still open, and those that went through
     * without counting yet.
     *
     * @return list<PaymentRecord>
     */
    private function toSettle(OrderView $order): array
    {
        $records = array_values(array_filter($this->payments->forOrder($order->id), static function (PaymentRecord $record): bool {
            return !$record->isCounted() && in_array($record->state, [PaymentRecord::ACTIVE, PaymentRecord::UNCOUNTED], true);
        }));
        $current = $this->payments->current($order->id);
        usort($records, static function (PaymentRecord $a, PaymentRecord $b) use ($current): int {
            $first = $current !== null ? $current->paymentId : '';

            return [$a->paymentId !== $first, $a->id] <=> [$b->paymentId !== $first, $b->id];
        });

        return $records;
    }

    /**
     * A payment that went through without counting (its refunds unread),
     * read again: counted now if it can be, a second payment once the order
     * is paid, and money the order holds, the payer charged, until then.
     *
     * @param array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true} $read
     *
     * @return array{0: string, 1: string}
     */
    private function uncounted(OrderView $order, PaymentRecord $record, array $read): array
    {
        $transaction = $read['transaction'];
        if (Settlement::namesAnotherOrder($order, $transaction)) {
            return [self::CLEAR, ''];
        }
        if ($read['outcome'] !== Client::OUTCOME_SUCCEEDED) {
            // It went through: whatever this read says, the payer was charged.
            return [self::WAIT, ''];
        }
        $status = strtolower($read['status']);
        if ($order->paid) {
            $this->settlement->noteSecondPayment($order, $record, $transaction);

            return [self::CLEAR, $status];
        }
        $this->settlement->apply($order, $record, $read, Settlement::SEEN_LATER);
        $counted = $this->payments->find($record->paymentId);

        return [$counted !== null && $counted->state !== PaymentRecord::UNCOUNTED ? self::CLEAR : self::WAIT, $status];
    }

    /**
     * One open payment: what it means for the order, and the status it ended
     * with ('' while it goes on, or when it was not read).
     *
     * @param array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true} $read
     *
     * @return array{0: string, 1: string}
     */
    private function one(OrderView $order, PaymentRecord $record, array $read, string $seen, bool $cancel, bool $left = false): array
    {
        $transaction = $read['transaction'];
        if (Settlement::namesAnotherOrder($order, $transaction)) {
            // Its payer may be paying it for that order: never cancelled from this one.
            return [self::CLEAR, ''];
        }
        $status = strtolower($read['status']);
        if ($read['outcome'] === Client::OUTCOME_SUCCEEDED) {
            if ($order->paid) {
                // Another of its payments paid it a moment ago.
                $this->settlement->remember($record, $transaction);
                $this->settlement->noteSecondPayment($order, $record, $transaction);

                return [self::CLEAR, $status];
            }
            $applied = $this->settlement->apply($order, $record, $read, $seen);
            $counted = $this->payments->find($record->paymentId);

            // Not counted yet (its refunds unread): the payer paid, and what
            // it holds is not known. Counted, or found to count nothing (in
            // another currency, which the merchant is told to refund, or
            // refunded in full): it can no longer be charged.
            return [$applied === Client::OUTCOME_PENDING || ($counted !== null && $counted->state === PaymentRecord::UNCOUNTED) ? self::WAIT : self::CLEAR, $status];
        }
        if (!empty($read['unreachable'])) {
            return [self::UNCHECKED, ''];
        }
        if ($status === 'processing' && $cancel && $left && !Ledger::openWithMoney($transaction)) {
            // Left by the payer for another method: cancelled, or, should
            // Wajub refuse (it went through meanwhile), settled as it is.
            return $this->cancel($order, $record);
        }
        if (Ledger::openWithMoney($transaction) || $status === 'processing') {
            $this->settlement->remember($record, $transaction);

            // Its status said: the scheduled task tells the merchant why the order waits.
            return [self::WAIT, $status];
        }
        if ($read['outcome'] === Client::OUTCOME_PENDING) {
            if (!$cancel) {
                return [self::WAIT, ''];
            }

            return $this->cancel($order, $record);
        }

        // Ended without taking any money.
        $this->settlement->apply($order, $record, $read);

        return [self::CLEAR, $status];
    }

    /**
     * Cancels a payment nobody paid yet, so it can never be approved
     * afterwards.
     *
     * @return array{0: string, 1: string}
     */
    private function cancel(OrderView $order, PaymentRecord $record): array
    {
        try {
            ($this->clientFor)($record->environment)->cancelPayment($record->paymentId);
        } catch (NotFoundException $e) {
            // Wajub no longer has it (pruned once expired, or never made in
            // this environment): nothing can charge it.
            $this->logger->info('Payment unknown to Wajub, set aside', ['order' => $order->id, 'payment' => $record->paymentId]);
            $record->state = PaymentRecord::FINAL;
            $this->payments->save($record);
            $this->settlement->forgetOutcome($record);

            return [self::CLEAR, 'gone'];
        } catch (ApiException $e) {
            if (Client::unanswered($e)) {
                return [self::UNCHECKED, ''];
            }
            // Refused: it ended, it is being paid, or the key may not touch
            // it. A read says which.
            $read = $this->settlement->outcome($record, true);
            if (!empty($read['unreachable']) || $read['transaction'] === []) {
                return [self::UNCHECKED, ''];
            }
            $status = strtolower($read['status']);
            if ($read['outcome'] === Client::OUTCOME_FAILED) {
                $this->settlement->apply($order, $record, $read);

                return [self::CLEAR, $status];
            }
            if ($read['outcome'] === Client::OUTCOME_SUCCEEDED) {
                $this->settlement->apply($order, $record, $read, Settlement::SEEN_LATER);

                return [self::CLEAR, $status];
            }
            $this->settlement->remember($record, $read['transaction']);

            return [self::WAIT, ''];
        } catch (\Throwable $e) {
            return [self::UNCHECKED, ''];
        }

        $record->status = 'cancelled';
        $record->state = PaymentRecord::FINAL;
        // Its webhook, which follows, says the shop cancelled it.
        $record->metadata[PaymentRecord::CANCELLED_BY_SHOP] = (string) time();
        $this->payments->save($record);
        $this->settlement->forgetOutcome($record);
        $this->logger->info('Open payment cancelled', ['order' => $order->id, 'payment' => $record->paymentId]);

        return [self::CLEAR, self::CANCELLED_HERE];
    }
}
