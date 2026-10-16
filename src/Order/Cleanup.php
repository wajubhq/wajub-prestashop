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

use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the module does on its own, from time to time: the address of
 * controllers/front/cron.php, which the merchant's scheduler calls, or
 * PrestaShop's Cron tasks manager (the cronjobs module) every hour. A port
 * of the WordPress plugin's OrderSettlement::recount() and
 * keepUnpaidOrder(), which WP-Cron and WooCommerce's hold-stock cleanup run
 * there; PrestaShop has neither.
 *
 * 1. The payments that went through but could not count yet, their refunds
 *    unread (a webhook or a payer's return met Wajub not answering): read
 *    again, and counted if they can be now (Order\Recounts, which also
 *    reads each a few minutes after it could not count). One still unread
 *    is pointed out to the merchant, once; one that went through once
 *    another paid its order charged the payer twice, and the merchant is
 *    told.
 * 2. The orders still "Awaiting Wajub payment" the configured delay after
 *    they were placed, or after their payment of the moment started when
 *    the payer started one again since (Settings::cancelAfter(); 0: never),
 *    which the payer left: cancelled, their products back in stock, as
 *    WooCommerce cancels an unpaid order once its stock hold runs out. Never
 *    one that holds money: a payment short of its total counted, live money
 *    whatever environment the shop is in now, a payment being paid in
 *    instalments, one Wajub is taking (`processing`), one that went through
 *    but could not count, one that went through in another currency (not
 *    refunded in full), or one Wajub did not answer for: what it holds is
 *    not known, and may be the order's whole total. A payment nobody paid yet is cancelled
 *    with Wajub first, so it can never be approved once the order is
 *    cancelled. Nor an order paid once, which the merchant set back since:
 *    nothing reads or settles it here.
 *
 * Each run asks Wajub of a bounded number of orders, the longest waiting
 * first; an order kept is looked at again an hour later at the soonest, so
 * the next ones get their turn. Each order under its lock, with the
 * services of its own shop: its key, its environment, its delay.
 *
 * One run at a time, by a lease in the module's state (StateStore::add()),
 * never a database lock: the run takes each order's lock in turn, and
 * MySQL 5.6 lets a request hold one named lock only (Support\DbLock). A
 * run that dies leaves its lease to lapse after RUN_FOR; one that outlasts
 * it lets the next run keep the lease it took meanwhile.
 */
final class Cleanup
{
    /** At most this many orders of payments that could not count are read again per run. */
    public const RECOUNTS_PER_RUN = 10;

    /** At most this many abandoned orders are looked at per run. */
    public const ORDERS_PER_RUN = 10;

    /** Seconds an order kept is left alone before a run looks at it again. */
    public const KEEP_FOR = 3600;

    /** Where the orders kept are listed, with when they may be looked at again. */
    private const KEPT = 'cleanup_kept';

    /** The run's lease, and how long it lasts should its request die. */
    private const RUN = 'cleanup_run';

    private const RUN_FOR = 900;

    /** @var callable(int): Services */
    private $servicesFor;

    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var StateStore */
    private $state;

    /** @var Recounts */
    private $recounts;

    /** @var Logger */
    private $logger;

    /** @var int */
    private $awaitingState;

    /**
     * @param callable(int): Services $servicesFor the services of a shop (Wajub::servicesFor())
     */
    public function __construct(callable $servicesFor, ShopOrders $orders, PaymentStore $payments, StateStore $state, Recounts $recounts, Logger $logger, int $awaitingState)
    {
        $this->servicesFor = $servicesFor;
        $this->orders = $orders;
        $this->payments = $payments;
        $this->state = $state;
        $this->recounts = $recounts;
        $this->logger = $logger;
        $this->awaitingState = $awaitingState;
    }

    /**
     * One run: what it read again, cancelled and kept.
     *
     * @return array{recounted: int, cancelled: list<int>, kept: list<int>}
     */
    public function run(?int $now = null): array
    {
        $now = $now ?? time();
        $done = ['recounted' => 0, 'cancelled' => [], 'kept' => []];
        // One run at a time: two would read the same payments.
        $lease = bin2hex(random_bytes(8));
        if (!$this->state->add(self::RUN, $lease, self::RUN_FOR)) {
            return $done;
        }
        try {
            $kept = $this->kept($now);

            foreach ($this->payments->uncounted(self::RECOUNTS_PER_RUN, array_keys($kept)) as $record) {
                if (isset($kept[$record->idOrder])) {
                    continue;
                }
                $kept[$record->idOrder] = $now + self::KEEP_FOR;
                if ($this->recounts->recount($record, $now)) {
                    ++$done['recounted'];
                    unset($kept[$record->idOrder]);
                }
            }

            $looked = 0;
            foreach ($this->orders->inState($this->awaitingState, self::ORDERS_PER_RUN * 3, array_keys($kept)) as $asked => $placed) {
                if ($looked >= self::ORDERS_PER_RUN) {
                    break;
                }
                // The orders one cart became are one: looked at once, by the first's id.
                $order = $this->orders->find($asked);
                if ($order === null || isset($kept[$order->id]) || in_array($order->id, $done['cancelled'], true)) {
                    continue;
                }
                $idOrder = $order->id;
                $services = ($this->servicesFor)($order->idShop);
                $minutes = $services->settings()->cancelAfter();
                if ($minutes <= 0) {
                    $kept[$idOrder] = $now + self::KEEP_FOR;

                    continue;
                }
                if ($this->waitingSince($idOrder, $placed) > $now - $minutes * 60) {
                    continue;
                }
                ++$looked;
                $verdict = $this->abandon($services, $idOrder, $minutes);
                if ($verdict === 'cancelled') {
                    $done['cancelled'][] = $idOrder;
                } elseif ($verdict === 'kept') {
                    $done['kept'][] = $idOrder;
                    $kept[$idOrder] = $now + self::KEEP_FOR;
                }
            }

            $this->state->set(self::KEPT, $kept, 7 * 86400);
            // What the pages ask to be read again follows what this run read.
            $this->recounts->refresh($now);
        } finally {
            // This run's lease only: should the run have outlasted it, the
            // next run took the lease, and keeps it.
            if ($this->state->get(self::RUN) === $lease) {
                $this->state->delete(self::RUN);
            }
        }

        return $done;
    }

    /**
     * An order the payer left, awaiting its payment past the delay:
     * 'cancelled', 'kept' (it holds money, or a payment may still go through
     * or could not be read), 'paid' (a payment read now paid it) or 'left'
     * (another request has it, or it moved on).
     */
    private function abandon(Services $services, int $idOrder, int $minutes): string
    {
        $name = 'order:' . $idOrder;
        if (!$services->lock()->acquire($name, 0)) {
            return 'left';
        }
        try {
            $order = $services->orders()->find($idOrder);
            if ($order === null || !$order->isWajub() || $order->paid || $order->stateId !== $this->awaitingState) {
                return 'left';
            }
            // Paid once, set back since by the merchant: never settled again here.
            if ($order->paidOnce) {
                return 'kept';
            }

            $ledger = $services->ledger();
            $settlement = $services->settlement();
            if ($ledger->held($order, 'live') > 0 || $ledger->held($order, 'sandbox') > 0) {
                // It holds money its ledger says: no call for its own payment.
                // Those that could not count are read again all the same.
                foreach ($ledger->uncounted($order) as $record) {
                    $settlement->readAgain($order, $record);
                    $order = $services->orders()->find($order->id) ?? $order;
                }
                if ($order->paid) {
                    return 'paid';
                }
                $this->noteKept($services, $order);

                return 'kept';
            }

            $settled = $services->openPayments()->settle($order, Settlement::SEEN_LATER);
            $order = $services->orders()->find($order->id) ?? $order;
            if ($settled['verdict'] === OpenPayments::PAID || $order->paid) {
                return 'paid';
            }
            // Went through without counting (in another currency): the payer
            // was charged. Read again (as kept, should this run have read it)
            // so that one refunded in full since lets the order go.
            foreach ($ledger->chargedUncounted($order) as $record) {
                $settlement->remember($record, $settlement->outcome($record)['transaction']);
            }
            if ($settled['verdict'] !== OpenPayments::CLEAR || $ledger->holdsMoney($order) || $ledger->uncounted($order) !== [] || $ledger->chargedUncounted($order) !== []) {
                $this->noteKept($services, $order, $settled['statuses']);

                return 'kept';
            }

            $services->orders()->setState($order, $services->orders()->canceledState(), false);
            $t = $services->translator();
            // One wording for one minute and one for more, each translated
            // whole (as Support\Ago): no language builds its plural from pieces.
            $services->orders()->note($order, 'abandoned', $minutes === 1
                ? $t->trans('This unpaid order was cancelled: its Wajub payment was not completed within 1 minute of the order being placed, and Wajub holds no money for it. Its products are back in stock.', [], 'Modules.Wajub.Admin')
                : $t->trans(
                    'This unpaid order was cancelled: its Wajub payment was not completed within %minutes% minutes of the order being placed, and Wajub holds no money for it. Its products are back in stock.',
                    ['%minutes%' => (string) $minutes],
                    'Modules.Wajub.Admin'
                ));
            // Its confirmation e-mail, held until it is paid, will never be
            // sent: it holds the customer's name, e-mail address and
            // addresses, kept no longer than needed.
            $services->heldConfirmations()->forgetOrder($order);
            $this->logger->info('Unpaid order cancelled', ['order' => $order->id, 'minutes' => $minutes]);

            return 'cancelled';
        } catch (\Throwable $e) {
            $this->logger->error('An unpaid order could not be checked', ['order' => $idOrder, 'error' => $e->getMessage()]);

            return 'kept';
        } finally {
            $services->lock()->release($name);
        }
    }

    /**
     * Since when the order waits for its payment: since it was placed, or
     * since its payment of the moment started, whichever is later. The payer
     * who starts a payment again from the order's pages ("Pay for this
     * order") has the whole delay to pay it.
     */
    private function waitingSince(int $idOrder, int $placed): int
    {
        $current = $this->payments->current($idOrder);
        $started = $current !== null && $current->dateAdd !== '' ? strtotime($current->dateAdd) : false;

        return $started !== false ? max($placed, $started) : $placed;
    }

    /**
     * Why an unpaid order was kept, for the merchant, once per reason: a
     * payment being paid in instalments, with what it collected so far, one
     * that went through but could not count, or one Wajub is still
     * processing (`$statuses`: Wajub's status by payment id, as this run
     * read them).
     *
     * @param array<string, string> $statuses
     */
    private function noteKept(Services $services, OrderView $order, array $statuses = []): void
    {
        $settlement = $services->settlement();
        foreach ($services->ledger()->uncounted($order) as $record) {
            $settlement->noteUncounted($order, $record);
        }
        // A payment Wajub is still processing (a card at its 3-D Secure
        // challenge, a phone yet to confirm) may still go through: the
        // merchant reads why the order waits past the delay, once.
        foreach ($statuses as $paymentId => $status) {
            if ($status === 'processing') {
                $services->orders()->note($order, 'kept_processing_' . md5((string) $paymentId), $services->translator()->trans(
                    'This unpaid order was not cancelled: Wajub payment %payment% may still go through (Wajub status: %status%). The order is looked at again once Wajub tells how the payment ended.',
                    ['%payment%' => (string) $paymentId, '%status%' => $status],
                    'Modules.Wajub.Admin'
                ));
            }
        }
        $open = $services->ledger()->instalmentsOpen($order);
        if ($open === null) {
            return;
        }
        // As last read: the run read it a moment ago.
        $read = $settlement->outcome($open);
        $transaction = $read['transaction'];
        $currency = strtoupper((string) ($transaction['currency'] ?? '')) ?: $order->currency;
        $services->orders()->note($order, 'kept_' . md5($open->paymentId), $services->translator()->trans(
            'This unpaid order was not cancelled: Wajub payment %payment%, paid in instalments, has collected %paid% so far.',
            ['%payment%' => $open->paymentId, '%paid%' => $services->money()->format(Ledger::paidSoFar($transaction !== [] ? $transaction : ['amount_paid' => $open->amountPaid, 'amount' => $open->amount]), $currency)],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * The orders kept by an earlier run, not to be looked at again yet: when
     * each may be, by order id.
     *
     * @return array<int, int>
     */
    private function kept(int $now): array
    {
        $kept = $this->state->get(self::KEPT);
        $until = [];
        foreach (is_array($kept) ? $kept : [] as $idOrder => $time) {
            if ((int) $time > $now) {
                $until[(int) $idOrder] = (int) $time;
            }
        }

        return $until;
    }
}
