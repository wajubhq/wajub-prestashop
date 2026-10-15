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
 * The Lock on the database's own named locks (GET_LOCK): held by the
 * request's connection, it waits for the other request rather than failing
 * at once, and goes with the connection should the request die before it
 * releases it, so none is ever left behind.
 *
 * Named after the shop's database and table prefix: one MySQL server may
 * hold several shops, whose order ids are the same numbers.
 *
 * A lock this request holds already (the refund forms' request keeps the
 * order's until its end, Refund\PendingSlips) is taken again at once, and
 * goes with the last release: the database is asked once each way, as
 * MySQL 5.6 does not count a connection's locks the way 5.7 and MariaDB do.
 */
final class DbLock implements Lock
{
    /** @var array<string, int> the locks this request holds, and how many times it took each */
    private $held = [];

    public function acquire(string $name, int $wait): bool
    {
        if (isset($this->held[$name])) {
            ++$this->held[$name];

            return true;
        }
        $taken = (int) \Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($this->name($name)) . '\', ' . max(0, $wait) . ')', false) === 1;
        if ($taken) {
            $this->held[$name] = 1;
        }

        return $taken;
    }

    public function release(string $name): void
    {
        if (!isset($this->held[$name])) {
            return;
        }
        if (--$this->held[$name] > 0) {
            return;
        }
        unset($this->held[$name]);
        \Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($this->name($name)) . '\')', false);
    }

    /** At most 64 characters, as MySQL takes them. */
    private function name(string $name): string
    {
        return 'wajub_' . md5((defined('_DB_NAME_') ? _DB_NAME_ : '') . '|' . _DB_PREFIX_ . '|' . $name);
    }
}
