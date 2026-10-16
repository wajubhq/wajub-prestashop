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
 * Stand-ins for the PrestaShop classes the module's adapters call, with the
 * behaviour the module relies on: Configuration's fallback from a shop to
 * its group to all shops, and its plain-text save (pSQL without `html`),
 * which strips tags and line breaks; its table, as Db reads it (a value
 * saved for all shops has no shop and no group: NULL); the shop selector's
 * context and the shops' groups; and PhpEncryption's round trip, which
 * fails with another key.
 */

final class Shop
{
    public const CONTEXT_SHOP = 1;

    public const CONTEXT_GROUP = 2;

    public const CONTEXT_ALL = 4;

    public static bool $multistore = false;

    public static int $context = self::CONTEXT_ALL;

    public static int $shop = 1;

    public static int $group = 1;

    /** @var array<int, int> shop id => group id, group 1 when not said */
    public static array $groups = [];

    public static function isFeatureActive(): bool
    {
        return self::$multistore;
    }

    public static function getGroupFromShop($shopId, $asId = true)
    {
        return self::$groups[(int) $shopId] ?? 1;
    }

    public static function getContext(): int
    {
        return self::$context;
    }

    public static function getContextShopID(bool $nullValueWithoutMultishop = false): ?int
    {
        if ($nullValueWithoutMultishop && !self::$multistore) {
            return null;
        }

        return self::$context === self::CONTEXT_SHOP ? self::$shop : null;
    }

    public static function getContextShopGroupID(bool $nullValueWithoutMultishop = false): ?int
    {
        if ($nullValueWithoutMultishop && !self::$multistore) {
            return null;
        }

        return self::$context === self::CONTEXT_ALL ? null : self::$group;
    }
}

final class Configuration
{
    /** @var array<string, array<string, array<int, string>>> 'global' | 'g<id>' | 's<id>' => key => lang => value */
    public static array $values = [];

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        $lang = (int) $idLang;
        if ($idShop === null || !Shop::isFeatureActive()) {
            $idShop = Shop::getContextShopID(true);
        }
        if ($idShopGroup === null || !Shop::isFeatureActive()) {
            $idShopGroup = Shop::getContextShopGroupID(true);
        }
        if ($idShop && isset(self::$values['s' . $idShop][$key][$lang])) {
            return self::$values['s' . $idShop][$key][$lang];
        }
        if ($idShopGroup && isset(self::$values['g' . $idShopGroup][$key][$lang])) {
            return self::$values['g' . $idShopGroup][$key][$lang];
        }

        return self::$values['global'][$key][$lang] ?? $default;
    }

    public static function getGlobalValue($key, $idLang = null)
    {
        return self::$values['global'][$key][(int) $idLang] ?? false;
    }

    public static function updateValue($key, $values, $html = false, $idShopGroup = null, $idShop = null): bool
    {
        if (preg_match('/^[a-zA-Z_0-9-]+$/', (string) $key) !== 1) {
            throw new RuntimeException("[{$key}] is not a valid configuration key");
        }
        if ($idShop === null || !Shop::isFeatureActive()) {
            $idShop = Shop::getContextShopID(true);
        }
        if ($idShopGroup === null || !Shop::isFeatureActive()) {
            $idShopGroup = Shop::getContextShopGroupID(true);
        }
        $level = $idShop ? 's' . $idShop : ($idShopGroup ? 'g' . $idShopGroup : 'global');
        foreach (is_array($values) ? $values : [0 => $values] as $lang => $value) {
            // pSQL($value, $html): without `html`, Db::escape() strips the
            // tags, after turning each line break into a <br />.
            $value = (string) $value;
            if (!$html) {
                $value = strip_tags(str_replace(["\r\n", "\r", "\n"], '<br />', $value));
            }
            self::$values[$level][$key][(int) $lang] = $value;
        }

        return true;
    }

    public static function updateGlobalValue($key, $values, $html = false): bool
    {
        foreach (is_array($values) ? $values : [0 => $values] as $lang => $value) {
            self::$values['global'][$key][(int) $lang] = (string) $value;
        }

        return true;
    }

    public static function deleteByName($key): bool
    {
        foreach (self::$values as $level => $keys) {
            unset(self::$values[$level][$key]);
        }

        return true;
    }
}

final class PhpEncryption
{
    private string $key;

    public function __construct($hexString)
    {
        $this->key = (string) $hexString;
    }

    public function encrypt($plaintext)
    {
        return base64_encode(hash('sha256', $this->key) . ':' . $plaintext);
    }

    public function decrypt($cipherText)
    {
        $decoded = base64_decode((string) $cipherText, true);
        $prefix = hash('sha256', $this->key) . ':';
        if ($decoded === false || strncmp($decoded, $prefix, strlen($prefix)) !== 0) {
            return false;
        }

        return substr($decoded, strlen($prefix));
    }
}

function pSQL($string, $htmlOK = false)
{
    return addslashes((string) $string);
}

/**
 * Answers the one query the adapters make of Configuration's table (its rows
 * for a name), and the database's named locks (Support\DbLock), which it
 * notes, as one connection holds them: as MySQL 5.7 and MariaDB do, or, with
 * `$mysql56`, as MySQL 5.6 does. `$others` are the locks other connections
 * hold: a GET_LOCK of one of them times out.
 */
final class Db
{
    /** @var list<string> the named-lock queries made, in order */
    public static array $lockQueries = [];

    /** @var array<string, int> the named locks the connection holds, and how many times */
    public static array $locks = [];

    /** @var array<string, true> the named locks other connections hold */
    public static array $others = [];

    /**
     * MySQL 5.6's named locks: one per connection. A GET_LOCK releases the
     * lock the connection holds, whatever its name (the MySQL 5.6 manual,
     * "GET_LOCK()": "a lock obtained with GET_LOCK() is released explicitly
     * by executing RELEASE_LOCK() or implicitly when your session terminates
     * [...] or when you execute a new GET_LOCK()"), and one taken twice is
     * held once.
     */
    public static bool $mysql56 = false;

    public static function getInstance(): self
    {
        return new self();
    }

    public function getValue($sql, $useCache = true)
    {
        if (preg_match("/^SELECT (GET_LOCK|RELEASE_LOCK)\\('([a-z0-9_]+)'/", (string) $sql, $match) !== 1) {
            throw new RuntimeException('Unexpected query: ' . $sql);
        }
        self::$lockQueries[] = $match[1];
        if ($match[1] === 'GET_LOCK') {
            if (isset(self::$others[$match[2]])) {
                return '0';
            }
            if (self::$mysql56) {
                self::$locks = [$match[2] => 1];

                return '1';
            }
            // As MySQL 5.7 and MariaDB count them: each GET_LOCK of the same
            // connection takes the lock once more, each RELEASE_LOCK once less.
            self::$locks[$match[2]] = (self::$locks[$match[2]] ?? 0) + 1;

            return '1';
        }
        if (!isset(self::$locks[$match[2]])) {
            return isset(self::$others[$match[2]]) ? '0' : null;
        }
        if (--self::$locks[$match[2]] === 0) {
            unset(self::$locks[$match[2]]);
        }

        return '1';
    }

    /** Whether another connection could take the lock `$name` now (5.6 or 5.7 alike). */
    public static function free(string $name): bool
    {
        return !isset(self::$locks[$name]) && !isset(self::$others[$name]);
    }

    public function executeS($sql, $array = true, $useCache = true)
    {
        if (preg_match("/FROM `ps_configuration` WHERE `name` = '([A-Z0-9_]+)'$/", (string) $sql, $match) !== 1) {
            throw new RuntimeException('Unexpected query: ' . $sql);
        }
        $rows = [];
        foreach (Configuration::$values as $level => $keys) {
            if (!isset($keys[$match[1]][0])) {
                continue;
            }
            $shop = preg_match('/^s(\d+)$/', $level, $id) === 1 ? $id[1] : null;
            $group = preg_match('/^g(\d+)$/', $level, $id) === 1 ? $id[1] : null;
            $rows[] = [
                'id_shop_group' => $shop !== null ? (string) Shop::getGroupFromShop((int) $shop) : $group,
                'id_shop' => $shop,
                'value' => $keys[$match[1]][0],
            ];
        }

        return $rows;
    }
}
