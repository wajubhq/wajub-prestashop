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
 * One named lock at a time per request. MySQL 5.6, which PrestaShop 8.1
 * still runs on, lets a connection hold one named lock only: a GET_LOCK of
 * another name silently releases the one it holds, and another request may
 * then take it. So no request takes a second name while it holds one (the
 * scheduled task's run and the checkout's cart are kept apart another way:
 * Order\Cleanup, controllers/front/validation.php); one that tries is
 * refused, as a lock another request holds is, and the merchant's log says
 * so: the same on MySQL 5.6, 5.7 and MariaDB, so that a path that would
 * hold two shows on any of them, never silently on 5.6 alone.
 *
 * A lock this request holds already (the refund forms' request keeps the
 * order's until its end, Refund\PendingSlips) is taken again at once, and
 * goes with the last release: the database is asked once each way, as
 * MySQL 5.6 does not count a connection's locks the way 5.7 and MariaDB do.
 * What the request holds is kept for the whole request, whichever of the
 * module's objects took it (each shop's services have their own): they
 * share one connection to the database.
 */
final class DbLock implements Lock
{
    /** @var array<string, int> the locks this request holds, and how many times it took each */
    private static $held = [];

    /** @var Logger|null */
    private $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    public function acquire(string $name, int $wait): bool
    {
        if (isset(self::$held[$name])) {
            ++self::$held[$name];

            return true;
        }
        if (self::$held !== []) {
            // Never two names at once: on MySQL 5.6 the one held would go.
            if ($this->logger !== null) {
                $this->logger->error('A second database lock was refused while the request holds one', ['held' => implode(', ', array_keys(self::$held)), 'asked' => $name]);
            }

            return false;
        }
        $taken = (int) \Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($this->name($name)) . '\', ' . max(0, $wait) . ')', false) === 1;
        if ($taken) {
            self::$held[$name] = 1;
        }

        return $taken;
    }

    public function release(string $name): void
    {
        if (!isset(self::$held[$name])) {
            return;
        }
        if (--self::$held[$name] > 0) {
            return;
        }
        unset(self::$held[$name]);
        \Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($this->name($name)) . '\')', false);
    }

    /**
     * The names this request holds now.
     *
     * @return list<string>
     */
    public static function held(): array
    {
        return array_keys(self::$held);
    }

    /** At most 64 characters, as MySQL takes them. */
    private function name(string $name): string
    {
        return 'wajub_' . md5((defined('_DB_NAME_') ? _DB_NAME_ : '') . '|' . _DB_PREFIX_ . '|' . $name);
    }
}
