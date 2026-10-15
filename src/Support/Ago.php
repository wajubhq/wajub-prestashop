<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * How long ago something happened, in words: "3 minutes", "2 hours",
 * "5 days". One wording for one and one for more, each translated whole,
 * so no language has to build a plural from pieces.
 */
final class Ago
{
    public static function since(int $then, Translator $t, ?int $now = null): string
    {
        $seconds = max(0, ($now ?? time()) - $then);

        if ($seconds < 60) {
            return $t->trans('less than a minute', [], 'Modules.Wajub.Admin');
        }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);

            return $minutes === 1
                ? $t->trans('1 minute', [], 'Modules.Wajub.Admin')
                : $t->trans('%count% minutes', ['%count%' => $minutes], 'Modules.Wajub.Admin');
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);

            return $hours === 1
                ? $t->trans('1 hour', [], 'Modules.Wajub.Admin')
                : $t->trans('%count% hours', ['%count%' => $hours], 'Modules.Wajub.Admin');
        }
        $days = intdiv($seconds, 86400);

        return $days === 1
            ? $t->trans('1 day', [], 'Modules.Wajub.Admin')
            : $t->trans('%count% days', ['%count%' => $days], 'Modules.Wajub.Admin');
    }
}
