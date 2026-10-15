<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The EventStore on the module's `wajub_event` table (Install\Schema): one
 * row per event id, unique, which is what makes a claim atomic.
 */
final class DbEventStore implements EventStore
{
    public const TABLE = 'wajub_event';

    /**
     * A claim older than this was left by a request that died while it
     * acted: the next delivery takes the event over.
     */
    private const STALE_AFTER = 120;

    public function claim(string $eventId, int $idShop, string $environment, string $type, string $objectId): string
    {
        $db = \Db::getInstance();
        $now = date('Y-m-d H:i:s');
        $db->execute(
            'INSERT IGNORE INTO `' . $this->table() . '` (`event_id`, `id_shop`, `environment`, `type`, `object_id`, `status`, `attempts`, `date_add`, `date_upd`) VALUES ('
            . '\'' . pSQL($eventId) . '\', ' . (int) $idShop . ', \'' . pSQL($environment) . '\', \'' . pSQL(substr($type, 0, 64)) . '\', \'' . pSQL(substr($objectId, 0, 64)) . '\', \'processing\', 1, \'' . $now . '\', \'' . $now . '\')',
            false
        );
        if (self::changedOne($db)) {
            return self::CLAIMED;
        }

        $row = $db->getRow('SELECT `id_wajub_event`, `status`, `date_upd` FROM `' . $this->table() . '` WHERE `event_id` = \'' . pSQL($eventId) . '\'', false);
        if (!is_array($row)) {
            return self::BUSY;
        }
        if (in_array($row['status'], [self::PROCESSED, self::IGNORED], true)) {
            return self::DUPLICATE;
        }
        if ($row['status'] === 'processing' && strtotime((string) $row['date_upd']) > time() - self::STALE_AFTER) {
            return self::BUSY;
        }

        // A failed event, or one a dead request left: taken over only if no
        // other request took it since this one read it.
        $db->execute(
            'UPDATE `' . $this->table() . '` SET `status` = \'processing\', `attempts` = `attempts` + 1, `error` = NULL, `date_upd` = \'' . $now . '\''
            . ' WHERE `id_wajub_event` = ' . (int) $row['id_wajub_event']
            . ' AND `status` = \'' . pSQL((string) $row['status']) . '\' AND `date_upd` = \'' . pSQL((string) $row['date_upd']) . '\'',
            false
        );

        return self::changedOne($db) ? self::CLAIMED : self::BUSY;
    }

    public function finish(string $eventId, string $status, string $error = ''): void
    {
        \Db::getInstance()->execute(
            'UPDATE `' . $this->table() . '` SET `status` = \'' . pSQL($status) . '\', `error` = ' . ($error !== '' ? '\'' . pSQL(mb_substr($error, 0, 255)) . '\'' : 'NULL') . ', `date_upd` = \'' . date('Y-m-d H:i:s') . '\''
            . ' WHERE `event_id` = \'' . pSQL($eventId) . '\'',
            false
        );
    }

    public function release(string $eventId): void
    {
        // Only while this request holds it: never an event another request
        // finished since.
        \Db::getInstance()->execute(
            'DELETE FROM `' . $this->table() . '` WHERE `event_id` = \'' . pSQL($eventId) . '\' AND `status` = \'processing\'',
            false
        );
    }

    public function purge(int $days): void
    {
        \Db::getInstance()->execute('DELETE FROM `' . $this->table() . '` WHERE `date_add` < \'' . date('Y-m-d H:i:s', time() - $days * 86400) . '\'', false);
    }

    /**
     * Whether the last statement changed exactly one row: what tells this
     * request's claim from another's.
     *
     * @phpstan-impure
     */
    private static function changedOne(\Db $db): bool
    {
        return (int) $db->Affected_Rows() === 1;
    }

    private function table(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }
}
