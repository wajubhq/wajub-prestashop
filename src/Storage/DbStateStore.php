<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Storage;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The StateStore on the module's own table, `wajub_state` (Install\Schema).
 *
 * Every read skips PrestaShop's query cache: a lock or a session read from a
 * cache another request has not refreshed yet would let two requests do
 * what only one may.
 */
final class DbStateStore implements StateStore
{
    public const TABLE = 'wajub_state';

    /** One write in this many also clears the rows that expired. */
    private const PURGE_ONE_IN = 50;

    /** The longest name the table keeps: the primary key of a utf8mb4 table. */
    private const NAME_MAX = 191;

    public function get(string $name): mixed
    {
        $row = $this->db()->getRow('SELECT `value`, `expires_at` FROM `' . $this->table() . '` WHERE `name` = \'' . pSQL($this->name($name)) . '\'', false);
        if (!is_array($row) || ($row['expires_at'] > 0 && (int) $row['expires_at'] <= time())) {
            return null;
        }

        return json_decode((string) $row['value'], true);
    }

    public function set(string $name, mixed $value, int $ttl = 0): void
    {
        $this->db()->execute(
            'INSERT INTO `' . $this->table() . '` (`name`, `value`, `expires_at`, `date_upd`) VALUES ('
            . $this->values($name, $value, $ttl)
            . ') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `expires_at` = VALUES(`expires_at`), `date_upd` = VALUES(`date_upd`)',
            false
        );
        $this->maybePurge();
    }

    public function add(string $name, mixed $value, int $ttl = 0): bool
    {
        // An expired row is no row: it goes first, so the insert can take
        // its place.
        $this->db()->execute('DELETE FROM `' . $this->table() . '` WHERE `name` = \'' . pSQL($this->name($name)) . '\' AND `expires_at` > 0 AND `expires_at` <= ' . time(), false);
        $this->db()->execute('INSERT IGNORE INTO `' . $this->table() . '` (`name`, `value`, `expires_at`, `date_upd`) VALUES (' . $this->values($name, $value, $ttl) . ')', false);

        return (int) $this->db()->Affected_Rows() === 1;
    }

    public function delete(string $name): void
    {
        $this->db()->execute('DELETE FROM `' . $this->table() . '` WHERE `name` = \'' . pSQL($this->name($name)) . '\'', false);
    }

    public function increment(string $name, int $ttl): int
    {
        $now = time();
        // MySQL assigns in order: `value` reads the old `expires_at`, so a
        // counter whose time ran out starts again from one.
        $this->db()->execute(
            'INSERT INTO `' . $this->table() . '` (`name`, `value`, `expires_at`, `date_upd`) VALUES ('
            . $this->values($name, 1, $ttl)
            . ') ON DUPLICATE KEY UPDATE'
            . ' `value` = IF(`expires_at` > 0 AND `expires_at` <= ' . $now . ', \'1\', CAST(CAST(`value` AS UNSIGNED) + 1 AS CHAR)),'
            . ' `expires_at` = IF(`expires_at` > 0 AND `expires_at` <= ' . $now . ', ' . ($now + max(1, $ttl)) . ', `expires_at`),'
            . ' `date_upd` = VALUES(`date_upd`)',
            false
        );

        return (int) $this->db()->getValue('SELECT `value` FROM `' . $this->table() . '` WHERE `name` = \'' . pSQL($this->name($name)) . '\'', false);
    }

    private function values(string $name, mixed $value, int $ttl): string
    {
        $encoded = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return '\'' . pSQL($this->name($name)) . '\', \'' . pSQL($encoded, true) . '\', ' . ($ttl > 0 ? time() + $ttl : 0) . ', \'' . date('Y-m-d H:i:s') . '\'';
    }

    /** A name the key keeps whole: a long one is shortened to a hash of itself. */
    private function name(string $name): string
    {
        return strlen($name) <= self::NAME_MAX ? $name : substr($name, 0, 120) . '#' . hash('sha256', $name);
    }

    private function maybePurge(): void
    {
        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            $this->db()->execute('DELETE FROM `' . $this->table() . '` WHERE `expires_at` > 0 AND `expires_at` <= ' . time(), false);
        }
    }

    private function table(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    private function db(): \Db
    {
        return \Db::getInstance();
    }
}
