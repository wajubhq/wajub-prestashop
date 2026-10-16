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
 * The RecountHint as a setting of the whole PrestaShop (all shops: one
 * scheduled task reads every shop's payments). PrestaShop reads every
 * setting once per request, so a page that asks costs no query; it is
 * written only when a payment cannot count, and around each read again.
 *
 * Written with one statement each, on the setting as the database holds it
 * now, never through Configuration::updateValue(), which compares with what
 * this request read when it began and may skip the write: a webhook that
 * brings it forward while a run of Recounts sets it, or the other way
 * round, never loses the other's time.
 */
final class ConfigRecountHint implements RecountHint
{
    /** The setting's value as a number: 0 for none. */
    private const VALUE = 'CAST(IFNULL(`value`, \'0\') AS UNSIGNED)';

    public function get(): int
    {
        return max(0, (int) \Configuration::getGlobalValue(Settings::RECOUNT_AT));
    }

    public function set(int $at): void
    {
        $this->write(max(0, $at), '', true);
    }

    public function lower(int $at): void
    {
        if ($at > 0) {
            $this->write($at, self::VALUE . ' = 0 OR ' . self::VALUE . ' > ' . $at, true);
        }
    }

    public function postpone(int $until): void
    {
        if ($until > 0) {
            $this->write($until, self::VALUE . ' > 0 AND ' . self::VALUE . ' < ' . $until, false);
        }
    }

    /**
     * Writes `$at` when the setting, as the database holds it now, meets
     * `$when` ('': always); `$whenNone`: whether a setting not made yet
     * (worth 0) is written. This request reads what the database holds
     * afterwards.
     */
    private function write(int $at, string $when, bool $whenNone): void
    {
        $db = \Db::getInstance();
        $table = '`' . _DB_PREFIX_ . 'configuration`';
        $id = (int) $db->getValue(
            'SELECT `id_configuration` FROM ' . $table . ' WHERE `name` = \'' . pSQL(Settings::RECOUNT_AT) . '\''
            . ' AND `id_shop_group` IS NULL AND `id_shop` IS NULL',
            false
        );
        if ($id <= 0) {
            // The first time: PrestaShop makes the setting.
            if ($whenNone) {
                \Configuration::updateGlobalValue(Settings::RECOUNT_AT, (string) $at);
            }

            return;
        }
        $db->execute(
            'UPDATE ' . $table . ' SET `value` = \'' . (int) $at . '\', `date_upd` = \'' . date('Y-m-d H:i:s') . '\''
            . ' WHERE `id_configuration` = ' . $id . ($when !== '' ? ' AND (' . $when . ')' : ''),
            false
        );
        $now = (int) $db->getValue('SELECT ' . self::VALUE . ' FROM ' . $table . ' WHERE `id_configuration` = ' . $id, false);
        \Configuration::set(Settings::RECOUNT_AT, (string) $now, 0, 0);
    }
}
