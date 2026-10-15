<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The ConfigStore on PrestaShop's Configuration, for one Scope.
 *
 * Configuration strips tags and line breaks from a value saved as plain
 * text (pSQL without `html`): what the module saves is either text it made
 * plain already, or JSON whose line breaks and brackets are escaped
 * (Settings::encode()), so nothing is lost on the way.
 */
final class ShopConfigStore implements ConfigStore
{
    /**
     * What savedAt() read this request, by key, for every scope: the keys a
     * shop pays with are looked up there on each read (Settings), and a
     * write through any scope's store forgets the key's.
     *
     * @var array<string, array<string, string>>
     */
    private static $savedAt = [];

    /** @var Scope */
    private $scope;

    public function __construct(Scope $scope)
    {
        $this->scope = $scope;
    }

    public function get(string $key, ?int $idLang = null): ?string
    {
        // Configuration answers the default given, null, for a key never
        // saved; its own documentation says a string or false.
        $value = \Configuration::get($key, $idLang, $this->group(), $this->shop(), null);

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        unset(self::$savedAt[$key]);
        \Configuration::updateValue($key, $value, false, $this->group(), $this->shop());
    }

    public function setLang(string $key, array $values): void
    {
        unset(self::$savedAt[$key]);
        \Configuration::updateValue($key, $values, false, $this->group(), $this->shop());
    }

    /**
     * Read from the table rather than through Configuration, whose reads
     * always fall back to the scopes above: one query for every scope. A
     * row of a shop that multistore no longer reads is listed too, which
     * errs on the side of a value still in use.
     */
    public function savedAt(string $key): array
    {
        if (isset(self::$savedAt[$key])) {
            return self::$savedAt[$key];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_shop_group`, `id_shop`, `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'',
            true,
            false
        );

        $values = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $shop = (int) ($row['id_shop'] ?? 0);
            $group = (int) ($row['id_shop_group'] ?? 0);
            $scope = $shop > 0 ? 's' . $shop : ($group > 0 ? 'g' . $group : 'all');
            $values[$scope] = (string) ($row['value'] ?? '');
        }

        return self::$savedAt[$key] = $values;
    }

    /**
     * Configuration deletes the row of the shop or group in context, which
     * is this scope's on the settings page, the only place that forgets a
     * value: in any other context, nothing is deleted.
     */
    public function forget(string $key): void
    {
        if ($this->scope->isAllShops() || Scope::fromContext()->slug() !== $this->scope->slug()) {
            return;
        }
        unset(self::$savedAt[$key]);
        \Configuration::deleteFromContext($key);
    }

    public function deleteEverywhere(string $key): void
    {
        unset(self::$savedAt[$key]);
        \Configuration::deleteByName($key);
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    /**
     * 0 rather than null for "no shop": Configuration reads null as "the
     * shop of the context", which is not this scope's when the context is
     * another (a webhook, an install, a test). Without multistore,
     * Configuration takes the context's ids whatever it is given.
     */
    private function shop(): int
    {
        return $this->scope->idShop ?? 0;
    }

    private function group(): int
    {
        return $this->scope->idShopGroup ?? 0;
    }
}
