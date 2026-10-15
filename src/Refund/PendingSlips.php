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
 * waits for the slip that records it.
 *
 * The slip PrestaShop makes next (actionOrderSlipAdd) records the refunds
 * Wajub made for it (slipMade()). One PrestaShop does not make (its command
 * refused once Wajub had refunded, or failed) leaves the money with the
 * customer and nothing on the order: at the end of the request (finish(),
 * a shutdown function) what Wajub refunded is recorded on a slip of its own
 * (RefundSync::recordWithoutSlip()). Unless PrestaShop made its slip after
 * all, out of the module's sight: it adds the slip before it runs the hook,
 * which stops at the first module whose hook fails (with a PHP error, or
 * any exception in debug mode; PrestaShop swallows the others). Then that
 * slip, the order's newest since Wajub was asked, records them
 * (RefundSync::recordOnUnseenSlip()): never a second slip of one refund.
 *
 * One instance per request: the module's.
 */
final class PendingSlips
{
    /**
     * By order id. `keys` null until the form's refund went through; `slip`
     * the order's newest credit slip then, before PrestaShop makes the form's.
     *
     * @var array<int, array{services: Services, locked: bool, keys: list<string>|null, slip: int}>
     */
    private $orders = [];

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
        $this->orders[$idOrder] = ['services' => $services, 'locked' => true, 'keys' => $this->orders[$idOrder]['keys'] ?? null, 'slip' => $this->orders[$idOrder]['slip'] ?? 0];

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
        ];
    }

    /** Whether the slip being made is the one of the form's refund of this request. */
    public function expects(int $idOrder): bool
    {
        return isset($this->orders[$idOrder]) && $this->orders[$idOrder]['keys'] !== null;
    }

    /**
     * PrestaShop made the form's slip: it records the refunds Wajub made for
     * it. The lock is kept until the end of the request, until PrestaShop has
     * written what the slip refunded.
     */
    public function slipMade(int $idOrder, int $idOrderSlip): void
    {
        if (!$this->expects($idOrder)) {
            return;
        }
        $keys = (array) $this->orders[$idOrder]['keys'];
        // Made: another slip of the order is not this form's.
        $this->orders[$idOrder]['keys'] = null;
        if ($keys !== [] && $idOrderSlip > 0) {
            $this->orders[$idOrder]['services']->refundSync()->linkSlip($keys, $idOrderSlip);
        }
    }

    /**
     * The end of the request: the refunds Wajub made that no slip of
     * PrestaShop's records are recorded on one of their own, or on the slip
     * PrestaShop made without the module seeing it, and every lock still held
     * is let go. Never throws: it runs once the response is sent.
     */
    public function finish(): void
    {
        foreach ($this->orders as $idOrder => $entry) {
            $services = $entry['services'];
            try {
                if (is_array($entry['keys']) && $entry['keys'] !== []) {
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
