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
 * When the module's scheduled task last ran, for the back office to say so
 * when it never runs: PrestaShop has nothing like WordPress's WP-Cron, so
 * the task runs only when the Cron tasks manager module, or the host's cron
 * calling controllers/front/cron.php, runs it, and nothing else tells the
 * merchant that unpaid orders are never cancelled. On PrestaShop, settings
 * of the whole installation (ConfigTaskLog: one task for every shop, read
 * without a query); in the tests, numbers.
 */
interface TaskLog
{
    /** Unix time the task last started; 0 when it never did. */
    public function lastRun(): int;

    /**
     * Unix time from which the task is waited for (the module installed, or
     * its first back office page since an update): a task that has not run
     * yet is late only some time after it. 0 when not set yet.
     */
    public function since(): int;

    /** The task starts now (`$at`). */
    public function ran(int $at): void;

    /** Sets since() to `$at`, unless it is set already. */
    public function watch(int $at): void;
}
