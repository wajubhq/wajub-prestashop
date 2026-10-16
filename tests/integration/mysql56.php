<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

/*
 * The module's locks on a real MySQL 5.6 server, which PrestaShop 8.1 still
 * accepts, and where a connection holds one named lock only: a GET_LOCK of
 * another name releases the one it holds (tests/integration/run-mysql56.sh
 * runs this in a shop's container, against env/db56.sh's server).
 *
 *     php mysql56.php <dir of the module's sources> <host> <database> <user> <password>
 *
 * Two connections stand for two requests: `a` runs the module's code
 * (Support\DbLock, Storage\DbStateStore, through a Db on PDO), `b` is the
 * other request, which tries to take what `a` holds. The module's tables are
 * created on 5.6 too (Install\Schema): its keys fit the 767 bytes 5.6 indexes.
 */

define('_PS_VERSION_', 'mysql56');
define('_DB_PREFIX_', 'ps_');
define('_DB_NAME_', (string) ($argv[3] ?? 'wajub'));

function pSQL($string, $htmlOK = false)
{
    return addslashes((string) $string);
}

/** PrestaShop's Db, as the module calls it, on one PDO connection. */
final class Db
{
    /** @var PDO */
    public static $pdo;

    /** @var list<string> the named-lock queries made, in order */
    public static array $lockQueries = [];

    private static int $affected = 0;

    public static function getInstance(): self
    {
        return new self();
    }

    public function getValue($sql, $useCache = true)
    {
        if (preg_match('/^SELECT (GET_LOCK|RELEASE_LOCK)\(/', (string) $sql, $match) === 1) {
            self::$lockQueries[] = $match[1];
        }
        $value = self::$pdo->query((string) $sql)->fetchColumn();

        return $value === false ? false : $value;
    }

    public function getRow($sql, $useCache = true)
    {
        $row = self::$pdo->query((string) $sql)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? false : $row;
    }

    public function execute($sql, $useCache = true)
    {
        self::$affected = (int) self::$pdo->exec((string) $sql);

        return true;
    }

    public function Affected_Rows()
    {
        return self::$affected;
    }
}

$sources = rtrim((string) ($argv[1] ?? ''), '/');
foreach (['Support/Lock.php', 'Support/Logger.php', 'Support/DbLock.php', 'Storage/StateStore.php', 'Storage/DbStateStore.php', 'Install/Schema.php'] as $file) {
    require $sources . '/' . $file;
}

use Wajub\PrestaShop\Install\Schema;
use Wajub\PrestaShop\Storage\DbStateStore;
use Wajub\PrestaShop\Support\DbLock;

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

$dsn = 'mysql:host=' . ($argv[2] ?? 'wajub-mysql56') . ';port=3306;dbname=' . _DB_NAME_ . ';charset=utf8mb4';
$a = new PDO($dsn, (string) ($argv[4] ?? 'wajub'), (string) ($argv[5] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$b = new PDO($dsn, (string) ($argv[4] ?? 'wajub'), (string) ($argv[5] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
Db::$pdo = $a;
$version = (string) $a->query('SELECT VERSION()')->fetchColumn();
check(str_starts_with($version, '5.6.'), 'a MySQL 5.6 server (' . $version . ')');

/** The name DbLock gives the database for `$name`. */
function lockName(string $name): string
{
    return 'wajub_' . md5(_DB_NAME_ . '|' . _DB_PREFIX_ . '|' . $name);
}

/** Whether connection `a` holds the lock `$name`, as the server says. */
function heldByA(string $name): bool
{
    global $a, $b;
    $holder = $b->query('SELECT IS_USED_LOCK(' . $b->quote(lockName($name)) . ')')->fetchColumn();

    return $holder !== null && $holder !== false && (int) $holder === (int) $a->query('SELECT CONNECTION_ID()')->fetchColumn();
}

/** Whether the other request (connection `b`) could take `$name` now: it takes it, and lets it go. */
function freeForB(string $name): bool
{
    global $b;
    $taken = (int) $b->query('SELECT GET_LOCK(' . $b->quote(lockName($name)) . ', 0)')->fetchColumn() === 1;
    if ($taken) {
        $b->query('SELECT RELEASE_LOCK(' . $b->quote(lockName($name)) . ')');
    }

    return $taken;
}

// The trouble: two locks at once, the way the checkout (cart, then order)
// and the scheduled task (its run, then each order) took them before.
$a->query('SELECT GET_LOCK(' . $a->quote(lockName('cart:7')) . ', 5)');
$a->query('SELECT GET_LOCK(' . $a->quote(lockName('order:70')) . ', 5)');
check(!heldByA('cart:7') && freeForB('cart:7'), 'MySQL 5.6: a second GET_LOCK lets the first go, and another request takes it');
$a->query('SELECT RELEASE_LOCK(' . $a->quote(lockName('order:70')) . ')');

// The module's lock: one name at a time.
$lock = new DbLock();
check($lock->acquire('cart:8', 5) && heldByA('cart:8') && !freeForB('cart:8'), 'DbLock: the cart\'s lock held, the other request kept out');
Db::$lockQueries = [];
check(!$lock->acquire('order:80', 5) && Db::$lockQueries === [], 'DbLock: a second name refused, the server not asked');
check(heldByA('cart:8') && !freeForB('cart:8') && freeForB('order:80'), 'DbLock: the cart\'s lock still held');
$lock->release('cart:8');
check(!heldByA('cart:8') && freeForB('cart:8'), 'the checkout lets the cart\'s go once the order exists');
check($lock->acquire('order:80', 5) && heldByA('order:80') && !freeForB('order:80'), 'then takes the order\'s, held');
check($lock->acquire('order:80', 5) && Db::$lockQueries === ['RELEASE_LOCK', 'GET_LOCK'], 'taken again by the same request: not asked again (5.6 holds a lock once)');
$lock->release('order:80');
check(heldByA('order:80') && !freeForB('order:80'), 'released once: still held');
$lock->release('order:80');
check(!heldByA('order:80') && freeForB('order:80'), 'released by the first taker too: let go');
check(DbLock::held() === [], 'nothing held');

// The module's tables, on 5.6.
foreach (Schema::statements(_DB_PREFIX_, 'InnoDB') as $table => $statement) {
    $a->exec('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
    try {
        $a->exec($statement);
        check(true, "table {$table} created on 5.6");
    } catch (PDOException $e) {
        check(false, "table {$table} created on 5.6: " . $e->getMessage());
    }
}

// The scheduled task: its run kept apart by a lease in the module's state,
// never a lock, while it takes each order's lock in turn.
$state = new DbStateStore();
check($state->add('cleanup_run', time(), 900), 'the scheduled task\'s run: its lease taken');
check($lock->acquire('order:90', 0) && heldByA('order:90'), 'and an order\'s lock with it');
Db::$pdo = $b;
check(!(new DbStateStore())->add('cleanup_run', time(), 900), 'another run (the other request) finds the lease taken, while the order\'s lock is held');
Db::$pdo = $a;
check(!freeForB('order:90'), 'the order\'s lock held too: both kept, on 5.6');
$lock->release('order:90');
$state->delete('cleanup_run');
Db::$pdo = $b;
check((new DbStateStore())->add('cleanup_run', time(), 900), 'once done, the next run takes the lease');
(new DbStateStore())->delete('cleanup_run');
Db::$pdo = $a;

foreach (array_keys(Schema::statements(_DB_PREFIX_, 'InnoDB')) as $table) {
    $a->exec('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
}

echo $failures === 0 ? "all checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
