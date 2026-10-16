<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Refund;

use Wajub\PrestaShop\Services;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The refunds of the order page's forms (Partial refund, Standard refund,
 * Return products) through one request, from the hook that refunds through
 * Wajub before PrestaShop makes its credit slip to the end of the request.
 *
 * The order's lock, taken before the form is read, is held until the end of
 * the request, not only while Wajub is asked: the same refund posted twice
 * (a double click, a page sent again) waits for the first request to end,
 * then reads the quantities PrestaShop has refunded meanwhile, and
 * PrestaShop refuses it before Wajub is asked again; a refund's webhook
 * waits for the slip that records it. A request that a PHP error ends lets
 * go of it too: the lock is the database connection's.
 *
 * The slip PrestaShop makes next records the refunds Wajub made for it as
 * PrestaShop adds it (slipAdded(), on actionObjectOrderSlipAddAfter), before
 * the hook that says it made one (actionOrderSlipAdd, slipMade()). That hook
 * stops at the first module whose hook fails (with a PHP error, or any
 * exception in debug mode; PrestaShop swallows the others), and PrestaShop
 * then writes nothing more of the refund, not even the quantities it
 * refunded. So until the request ends well, the order says PrestaShop may
 * have stopped after the slip (RefundSync::recordOnUnseenSlip()): a module
 * after this one on that hook may still fail once it reached the module.
 * The note is taken back at the end of a request whose hook reached the
 * module and that answered without an error (RefundSync::slipSeen()). A PHP
 * error ends the request at once, before any end of request: what had to
 * be recorded is recorded by then, and the note stays.
 *
 * One slip PrestaShop does not make (its command refused once Wajub had
 * refunded, or failed) leaves the money with the customer and nothing on
 * the order: at the end of the request (finish(), on Symfony's
 * kernel.terminate: Refund\RefundFormsEnd) what Wajub refunded is recorded
 * on a slip of its own (RefundSync::recordWithoutSlip()), or, should a PHP
 * error have ended the request first, once Wajub's webhook says the refund
 * went through (RefundSync::webhook()). Never a second slip of one refund:
 * a slip PrestaShop added out of the module's sight, the order's newest
 * since Wajub was asked, records it at the end of the request instead.
 *
 * A refund Wajub refused makes no slip (RefusedRefund): should PrestaShop
 * make one all the same, it is never refunded through Wajub after the
 * fact, which could give the customer twice what Wajub had refunded of it
 * (refused()).
 *
 * Keyed by the first order of the orders a cart became (ShopOrders::find()),
 * whichever of them the merchant refunds: they share one lock.
 *
 * One instance per request: the module's.
 */
final class PendingSlips
{
    /**
     * By order id. `keys` null until the form's refund went through, and
     * again once PrestaShop's hook told the module of its slip; `slip` the
     * order's newest credit slip then, before PrestaShop makes the form's;
     * `made` the form's slip, once PrestaShop added it (0 until then);
     * `seen` that slip once PrestaShop's hook told the module of it, its
     * note taken back at the end of the request (0 until then).
     *
     * @var array<int, array{services: Services, locked: bool, keys: list<string>|null, slip: int, made: int, seen: int}>
     */
    private $orders = [];

    /** @var array<int, string> the orders whose refund Wajub refused this request, with why */
    private $refused = [];

    /**
     * Takes the order's lock for the rest of the request, waiting up to
     * `$wait` seconds for another request to finish with it. True when held.
     */
    public function lock(int $idOrder, Services $services, int $wait): bool
    {
        if (!empty($this->orders[$idOrder]['locked'])) {
            return true;
        }
        if (!$services->lock()->acquire(self::lockName($idOrder), $wait)) {
            return false;
        }
        $this->orders[$idOrder] = [
            'services' => $services,
            'locked' => true,
            'keys' => $this->orders[$idOrder]['keys'] ?? null,
            'slip' => $this->orders[$idOrder]['slip'] ?? 0,
            'made' => $this->orders[$idOrder]['made'] ?? 0,
            'seen' => $this->orders[$idOrder]['seen'] ?? 0,
        ];

        return true;
    }

    /** Lets go of the order's lock before the end of the request: nothing of the form waits on it. */
    public function unlock(int $idOrder): void
    {
        if (empty($this->orders[$idOrder]['locked'])) {
            return;
        }
        $this->orders[$idOrder]['locked'] = false;
        $this->orders[$idOrder]['services']->lock()->release(self::lockName($idOrder));
    }

    /**
     * The form's refund went through: the slip PrestaShop makes next is its
     * own, and records the refunds Wajub made for it under `$keys` (none for
     * a refund Wajub had nothing to do with).
     *
     * @param list<string> $keys
     */
    public function expect(int $idOrder, Services $services, array $keys): void
    {
        $order = $keys !== [] ? $services->orders()->find($idOrder) : null;
        $this->orders[$idOrder] = [
            'services' => $services,
            'locked' => !empty($this->orders[$idOrder]['locked']),
            'keys' => $keys,
            'slip' => $order !== null ? $services->orders()->lastSlip($order) : 0,
            'made' => 0,
            'seen' => $this->orders[$idOrder]['seen'] ?? 0,
        ];
    }

    /** Whether the slip being made is the one of the form's refund of this request. */
    public function expects(int $idOrder): bool
    {
        return isset($this->orders[$idOrder]) && $this->orders[$idOrder]['keys'] !== null;
    }

    /** The form's refund was refused (RefusedRefund): PrestaShop is to make no slip of it. */
    public function refuse(int $idOrder, string $why): void
    {
        $this->refused[$idOrder] = $why;
    }

    /**
     * Why the form's refund of the order was refused this request, '' when
     * it was not: a slip PrestaShop makes of it all the same is never
     * refunded through Wajub.
     */
    public function refused(int $idOrder): string
    {
        return $this->refused[$idOrder] ?? '';
    }

    /**
     * PrestaShop added the form's slip (it runs actionObjectOrderSlipAddAfter
     * as it saves it, before any module hears it made one): the slip records
     * the refunds Wajub made for it now, whatever stops PrestaShop next, with
     * the note that PrestaShop may have stopped after it, until its hook
     * tells the module of it (slipMade()).
     */
    public function slipAdded(int $idOrder, int $idOrderSlip): void
    {
        if ($idOrderSlip <= 0 || !$this->expects($idOrder) || $this->orders[$idOrder]['made'] > 0) {
            return;
        }
        $this->orders[$idOrder]['made'] = $idOrderSlip;
        $keys = (array) $this->orders[$idOrder]['keys'];
        if ($keys === []) {
            return;
        }
        $services = $this->orders[$idOrder]['services'];
        $order = $services->orders()->find($idOrder);
        if ($order !== null) {
            $services->refundSync()->recordOnUnseenSlip($order, $keys, $idOrderSlip);
        }
    }

    /**
     * PrestaShop's hook told the module of the form's slip
     * (actionOrderSlipAdd): it went on with the refund. The slip records the
     * refunds Wajub made for it, as PrestaShop added it (slipAdded()), whose
     * note is taken back once the request ends well (finish()), or now. The
     * lock is kept until the end of the request, until PrestaShop has
     * written what the slip refunded.
     */
    public function slipMade(int $idOrder, int $idOrderSlip): void
    {
        if (!$this->expects($idOrder)) {
            return;
        }
        $keys = (array) $this->orders[$idOrder]['keys'];
        $made = $this->orders[$idOrder]['made'];
        // Made: another slip of the order is not this form's.
        $this->orders[$idOrder]['keys'] = null;
        if ($keys === []) {
            return;
        }
        $services = $this->orders[$idOrder]['services'];
        if ($made > 0) {
            // A module after this one on the hook may still fail, and
            // PrestaShop write nothing of the quantities it refunded.
            $this->orders[$idOrder]['seen'] = $made;
        } elseif ($idOrderSlip > 0) {
            $services->refundSync()->linkSlip($keys, $idOrderSlip);
        }
    }

    /**
     * The end of the request: the refunds Wajub made that no slip of
     * PrestaShop's records are recorded on one of their own, or on the slip
     * PrestaShop made without the module seeing it; the note that PrestaShop
     * may have stopped after a slip it told the module of is taken back when
     * the request answered without an error (`$answered`); and every lock
     * still held is let go. Never throws: it runs once the response is sent.
     */
    public function finish(bool $answered = true): void
    {
        foreach ($this->orders as $idOrder => $entry) {
            $services = $entry['services'];
            // Done with: a slip recorded below is the module's, not the form's.
            $this->orders[$idOrder]['keys'] = null;
            try {
                if ($entry['seen'] > 0) {
                    $order = $services->orders()->find($idOrder);
                    if (!$answered) {
                        $services->logger()->error('The request ended with an error after PrestaShop made the credit slip of a refund Wajub made: the order says to check its refunded quantities', ['order' => $idOrder, 'slip' => $entry['seen']]);
                    } elseif ($order !== null) {
                        $services->refundSync()->slipSeen($order, $entry['seen']);
                    }
                }
                if (is_array($entry['keys']) && $entry['keys'] !== [] && $entry['made'] > 0) {
                    // Recorded on the slip as PrestaShop added it, with the
                    // note that it stopped there: its hook never reached the module.
                    $services->logger()->error('PrestaShop stopped after the credit slip of a refund Wajub made: recorded on it', ['order' => $idOrder, 'slip' => $entry['made']]);
                } elseif (is_array($entry['keys']) && $entry['keys'] !== []) {
                    $order = $services->orders()->find($idOrder);
                    $made = $order !== null ? $services->orders()->lastSlip($order) : 0;
                    if ($order !== null && $made > $entry['slip']) {
                        $services->refundSync()->recordOnUnseenSlip($order, $entry['keys'], $made);
                        $services->logger()->error('PrestaShop made the credit slip of a refund Wajub made without the module seeing it: recorded on it', ['order' => $idOrder, 'slip' => $made]);
                    } elseif ($order !== null) {
                        $slip = $services->refundSync()->recordWithoutSlip($order, $entry['keys']);
                        $services->logger()->error('PrestaShop made no credit slip of a refund Wajub made: recorded on one of its own', ['order' => $idOrder, 'slip' => $slip]);
                    }
                }
            } catch (\Throwable $e) {
                $services->logger()->error('A refund Wajub made could not be recorded on its order', ['order' => $idOrder, 'error' => $e->getMessage()]);
            } finally {
                $this->unlock($idOrder);
            }
            unset($this->orders[$idOrder]);
        }
    }

    private static function lockName(int $idOrder): string
    {
        return 'order:' . $idOrder;
    }
}
