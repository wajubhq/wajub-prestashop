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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * When the soonest payment that could not count is due to be read again
 * (Order\Recounts): a time every page of the shop can read without asking
 * the database, so that the pages ask the shop to read it again
 * (controllers/front/recount.php) only once one is due. On PrestaShop, a
 * setting (ConfigRecountHint); in the tests, a number.
 */
interface RecountHint
{
    /** Unix time the soonest is due at; 0 when none is. */
    public function get(): int;

    /** Sets it: 0 when no payment waits to be read again. */
    public function set(int $at): void;

    /**
     * Brings it forward to `$at`, unless one is due sooner, as the hint is
     * now, not as this request read it: a run that sets it meanwhile does
     * not hide `$at`.
     */
    public function lower(int $at): void;

    /**
     * Moves it on to `$until` while it is set to an earlier time, as the
     * hint is now: the pages ask no sooner, whatever a run does, or dies
     * doing, meanwhile. A hint that is not set (0) stays so.
     */
    public function postpone(int $until): void;
}
