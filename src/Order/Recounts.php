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

use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A payment that went through but could not count, its refunds unread as
 * its webhook came or its payer returned, read again a few minutes later.
 * A port of the WordPress plugin's OrderSettlement::scheduleRecount() and
 * recount(): Wajub sends the delivery once it got a 2xx, so nothing else
 * would read it again before the scheduled task's next run, an hour later
 * with PrestaShop's Cron tasks manager, or never on a shop that has none.
 *
 * PrestaShop has nothing like WP-Cron: Settlement writes on the payment when
 * it is due (DELAY from now) and brings the shop's RecountHint forward;
 * from then on, the next pages of the shop (the storefront's and the back
 * office's) ask the shop to read it again (controllers/front/recount.php,
 * from the visitor's browser once the page has loaded, so that no page
 * waits for Wajub), and run() reads those that are due. One run at a time,
 * a few payments per run, each under its order's lock; one still unread is
 * pointed out to the merchant, once, and read again later (RETRY_AFTER),
 * then left to the scheduled task and to the order's page in the back
 * office, which read it on every run and every visit.
 *
 * run() asks Wajub nothing while none is due, whoever asks, as often as
 * they like: the recount address takes no secret. Once one is due, each
 * read moves it on, whatever comes of it: counted, still unread or failed
 * (due again later, RETRY_AFTER, then no more), its order gone (no more).
 * While one stays due (another request holds its order, more are due than
 * a run reads), or a run is under way or died, the pages ask again a minute
 * later (AGAIN_AFTER), never on every page.
 */
final class Recounts
{
    /** Seconds after it could not count that a payment is read again: the plugin's RECOUNT_DELAY. */
    public const DELAY = 300;

    /**
     * Seconds before each next read once one failed: 15 minutes, then an
     * hour; past those, the scheduled task and the order's page alone.
     */
    public const RETRY_AFTER = [900, 3600];

    /**
     * Seconds the pages wait before they ask again while a payment is still
     * due after a run, or while a run is under way.
     */
    public const AGAIN_AFTER = 60;

    /** At most this many payments are read per run. */
    public const PER_RUN = 5;

    /** On the payment (its metadata): when it is due to be read again, and how many reads failed. */
    public const DUE = 'recount_at';

    public const TRIES = 'recount_tries';

    /** The run's lease: one run at a time, and no longer than this should its request die. */
    private const RUN = 'recount_run';

    /**
     * Longer than a run lasts: PER_RUN payments, each read with the open
     * payments of its order (a payment and its refunds, each call up to
     * Client::TIMEOUT). A run that outlasted it would leave the next one
     * its lease (run()).
     */
    private const RUN_FOR = 600;

    /**
     * How many payments due to be read again are looked at to find those
     * due now: those whose reads ran out are not among them.
     */
    private const LOOK_AT = 100;

    /** @var callable(int): Services */
    private $servicesFor;

    /** @var PaymentStore */
    private $payments;

    /** @var StateStore */
    private $state;

    /** @var RecountHint */
    private $hint;

    /** @var Logger */
    private $logger;

    /**
     * @param callable(int): Services $servicesFor the services of a shop (Wajub::servicesFor())
     */
    public function __construct(callable $servicesFor, PaymentStore $payments, StateStore $state, RecountHint $hint, Logger $logger)
    {
        $this->servicesFor = $servicesFor;
        $this->payments = $payments;
        $this->state = $state;
        $this->hint = $hint;
        $this->logger = $logger;
    }

    /**
     * Marks a payment that could not count as due to be read again DELAY
     * from now, unless it is due already: a payment read again and again
     * (the order's confirmation page asks every few seconds) keeps its
     * first due time; one read again by the pages as often as RETRY_AFTER
     * says is left to the scheduled task and the order's page, which fail
     * to read it again without the pages asking anew. When it is due, 0
     * when nothing changed: the caller saves the payment, then brings the
     * shop's RecountHint forward to it (RecountHint::lower()), in that
     * order, so that a run that reads the payments next finds it.
     */
    public static function schedule(PaymentRecord $record, int $now): int
    {
        if ((int) $record->meta(self::DUE) > 0 || (int) $record->meta(self::TRIES) > count(self::RETRY_AFTER)) {
            return 0;
        }
        $record->setMeta(self::DUE, (string) ($now + self::DELAY));
        $record->setMeta(self::TRIES, '');

        return $now + self::DELAY;
    }

    /** Whether a payment is due to be read again: what the pages ask the shop for. */
    public function due(int $now): bool
    {
        $at = $this->hint->get();

        return $at > 0 && $at <= $now;
    }

    /**
     * Reads again the payments due by `$now`, at most PER_RUN: how many
     * count now. Nothing, without a call to Wajub, while none is due or
     * another run is under way; the pages then ask again a minute later.
     */
    public function run(int $now): int
    {
        if (!$this->due($now)) {
            return 0;
        }
        // From now on the pages ask again a minute from now at the
        // soonest, whether another run reads the payments, one died with
        // its lease, or this one dies too.
        $this->hint->postpone($now + self::AGAIN_AFTER);
        $lease = bin2hex(random_bytes(8));
        if (!$this->state->add(self::RUN, $lease, self::RUN_FOR)) {
            return 0;
        }
        $counted = 0;
        try {
            $read = 0;
            foreach ($this->payments->uncountedWith(self::DUE, self::LOOK_AT) as $record) {
                $at = (int) $record->meta(self::DUE);
                if ($at <= 0 || $at > $now) {
                    continue;
                }
                if (++$read > self::PER_RUN) {
                    break;
                }
                if ($this->recount($record, $now)) {
                    ++$counted;
                }
            }
        } finally {
            try {
                $this->refresh($now);
            } finally {
                // This run's lease only: should the run have outlasted it,
                // the next run took the lease, and keeps it.
                if ($this->state->get(self::RUN) === $lease) {
                    $this->state->delete(self::RUN);
                }
            }
        }

        return $counted;
    }

    /**
     * Sets the shop's RecountHint to the soonest payment still due to be
     * read again, after a run, or once the scheduled task has read them; a
     * minute from now while one is still due. Read twice: a payment that
     * could not count meanwhile, saved after the first read, is found by the
     * second, should the time it brought the hint forward to have been
     * written over by the first (Settlement saves the payment, then brings
     * the hint forward).
     */
    public function refresh(int $now): void
    {
        $at = $this->next($now);
        $this->hint->set($at);
        $again = $this->next($now);
        if ($again !== $at) {
            $this->hint->lower($again);
        }
    }

    /**
     * Reads again the payments of the order of one that could not count,
     * under the order's lock, as its webhook would have: counted now if
     * they can be; still unread, the merchant is told, once, and, with
     * `$now`, the payment, when it was due, is due again later
     * (RETRY_AFTER), or no more. A read that fails moves it on the same
     * way; one whose order is gone is due no more. True when the order has
     * none left unread. Nothing while another request holds the order: a
     * run a minute later reads it.
     *
     * The scheduled task reads them all, due or not yet: one not due yet
     * keeps its due time and its reads, so that the pages still read it
     * when it is due, as the plugin reads it five minutes on.
     */
    public function recount(PaymentRecord $record, ?int $now = null): bool
    {
        $services = ($this->servicesFor)($record->idShop);
        $lock = $services->lock();
        $name = '';
        try {
            $orders = $services->orders();
            $wanted = 'order:' . $orders->leadId($record->idOrder);
            if (!$lock->acquire($wanted, 0)) {
                return false;
            }
            $name = $wanted;
            $order = $orders->find($record->idOrder);
            if ($order === null) {
                // Deleted from the shop: nothing to count it toward.
                $this->forget($record);

                return false;
            }
            $services->openPayments()->settle($order, Settlement::SEEN_LATER, [], false);
            $order = $orders->find($order->id) ?? $order;
            $left = $services->ledger()->uncounted($order);
            if (!$order->paid) {
                foreach ($left as $still) {
                    $services->settlement()->noteUncounted($order, $still);
                }
            }
            if ($now !== null) {
                foreach ($left as $still) {
                    $this->later($still, $now);
                }
            }

            return $left === [];
        } catch (\Throwable $e) {
            $this->logger->error('A payment could not be read again', ['order' => $record->idOrder, 'payment' => $record->paymentId, 'error' => $e->getMessage()]);
            // Read again later, as one still unread, whether the order's
            // lock was taken or not (its order not found, say): never on
            // the next page, which would ask again for nothing.
            if ($now !== null) {
                try {
                    $this->later($record, $now);
                } catch (\Throwable $ignored) {
                    // The pages wait a minute all the same (run()).
                }
            }

            return false;
        } finally {
            if ($name !== '') {
                $lock->release($name);
            }
        }
    }

    /**
     * A payment still unread after a read, when it was due: due again later,
     * or no more. One not due yet (read by the scheduled task) keeps its due
     * time and its reads.
     */
    private function later(PaymentRecord $record, int $now): void
    {
        $fresh = $this->payments->find($record->paymentId) ?? $record;
        $at = (int) $fresh->meta(self::DUE);
        if ($fresh->state !== PaymentRecord::UNCOUNTED || $at <= 0 || $at > $now) {
            return;
        }
        $tries = (int) $fresh->meta(self::TRIES) + 1;
        $fresh->setMeta(self::TRIES, (string) $tries);
        $fresh->setMeta(self::DUE, isset(self::RETRY_AFTER[$tries - 1]) ? (string) ($now + self::RETRY_AFTER[$tries - 1]) : '');
        $this->payments->save($fresh);
    }

    /** A payment whose order is gone: never due again. */
    private function forget(PaymentRecord $record): void
    {
        $fresh = $this->payments->find($record->paymentId) ?? $record;
        if ($fresh->meta(self::DUE) === '') {
            return;
        }
        $this->logger->info('A payment due to be read again belongs to an order the shop no longer has: it is read again no more', ['order' => $record->idOrder, 'payment' => $record->paymentId]);
        $fresh->setMeta(self::DUE, '');
        $this->payments->save($fresh);
    }

    /**
     * When the pages should ask next: when the soonest payment is due, a
     * minute from now while one is due already, 0 when none is.
     */
    private function next(int $now): int
    {
        $soonest = 0;
        foreach ($this->payments->uncountedWith(self::DUE, self::LOOK_AT) as $record) {
            $at = (int) $record->meta(self::DUE);
            if ($at > 0 && ($soonest === 0 || $at < $soonest)) {
                $soonest = $at;
            }
        }

        return $soonest > 0 && $soonest <= $now ? $now + self::AGAIN_AFTER : $soonest;
    }
}
