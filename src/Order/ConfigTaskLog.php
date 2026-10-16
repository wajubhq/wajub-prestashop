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

use Wajub\PrestaShop\Settings\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The TaskLog as two settings of the whole PrestaShop (all shops: one
 * scheduled task looks at every shop's orders), which PrestaShop reads once
 * per request: the back office's pages say whether the task runs without a
 * query of their own. Written once per run of the task, and once at install.
 */
final class ConfigTaskLog implements TaskLog
{
    public function lastRun(): int
    {
        return max(0, (int) \Configuration::getGlobalValue(Settings::TASK_LAST_RUN));
    }

    public function since(): int
    {
        return max(0, (int) \Configuration::getGlobalValue(Settings::TASK_SINCE));
    }

    public function ran(int $at): void
    {
        \Configuration::updateGlobalValue(Settings::TASK_LAST_RUN, (string) max(0, $at));
    }

    public function watch(int $at): void
    {
        if ($this->since() === 0 && $at > 0) {
            \Configuration::updateGlobalValue(Settings::TASK_SINCE, (string) $at);
        }
    }
}
