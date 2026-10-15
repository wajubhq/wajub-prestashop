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
 * Where the settings live, for one scope of a multistore shop: a shop, a
 * group of shops, or all of them (Scope). A read falls back as PrestaShop's
 * Configuration does, from the shop to its group to the value for all shops;
 * a write is for the scope only.
 */
interface ConfigStore
{
    /**
     * The value for this scope, or the one it inherits; null when none was
     * ever saved.
     */
    public function get(string $key, ?int $idLang = null): ?string;

    public function set(string $key, string $value): void;

    /**
     * @param array<int, string> $values by language id
     */
    public function setLang(string $key, array $values): void;

    /**
     * Every value saved under the key, by the scope it is saved for, named
     * as Scope::slug() names it: 'all', 'g<group id>', 's<shop id>'. A value
     * a scope only inherits shows under the scope that saved it: what tells
     * a shop's own key from the key of all shops it reads (language 0 only).
     *
     * @return array<string, string>
     */
    public function savedAt(string $key): array;

    /**
     * Removes the value this scope saved itself, if any, so that it reads
     * the one of the scope above again. Never for all shops.
     */
    public function forget(string $key): void;

    /** Removes the key from every shop, group and language: uninstalling. */
    public function deleteEverywhere(string $key): void;

    public function scope(): Scope;
}
