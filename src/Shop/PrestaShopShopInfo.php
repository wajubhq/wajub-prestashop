<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Shop;

use Wajub\PrestaShop\Settings\Scope;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The ShopInfo of a PrestaShop shop: the shop of the scope; for a group, the
 * group's shop the back office opens by default (or its first); for all
 * shops, the default shop.
 *
 * In a multistore, the webhook's address names the scope it was registered
 * for (`scope=all`, `g2`, `s3`): each shop, group or "All shops" that saves
 * its own connection has its own endpoint in Wajub, with its own signing
 * secret, and the webhook verifies a delivery with the secrets of the scope
 * its address names, whichever shop's domain it reaches.
 */
final class PrestaShopShopInfo implements ShopInfo
{
    /** @var Scope */
    private $scope;

    /** @var int */
    private $idShop;

    /** @var string */
    private $moduleVersion;

    public function __construct(Scope $scope, string $moduleVersion)
    {
        $this->scope = $scope;
        $this->idShop = $scope->idShop ?? self::shopOf($scope);
        $this->moduleVersion = $moduleVersion;
    }

    /** The shop that speaks for a group, or for all shops. */
    private static function shopOf(Scope $scope): int
    {
        $default = (int) \Configuration::get('PS_SHOP_DEFAULT');
        if ($scope->idShopGroup === null) {
            return $default;
        }
        $shops = array_map('intval', (array) \Shop::getShops(true, $scope->idShopGroup, true));
        if ($shops === [] || in_array($default, $shops, true)) {
            return $default;
        }
        sort($shops);

        return $shops[0];
    }

    public function id(): int
    {
        return $this->idShop;
    }

    public function name(): string
    {
        $shop = new \Shop($this->idShop);
        $name = \Validate::isLoadedObject($shop) ? (string) $shop->name : '';

        return $name !== '' ? $name : (string) \Configuration::get('PS_SHOP_NAME', null, null, $this->idShop);
    }

    public function baseUrl(): string
    {
        $shop = new \Shop($this->idShop);
        if (!\Validate::isLoadedObject($shop)) {
            return '';
        }

        return (string) $shop->getBaseURL(true, true);
    }

    public function shopIds(): array
    {
        if ($this->scope->idShop !== null || !\Shop::isFeatureActive()) {
            return [$this->idShop];
        }

        return array_values(array_map('intval', (array) \Shop::getShops(false, $this->scope->idShopGroup, true)));
    }

    public function webhookUrl(): string
    {
        // In the shop's default language: the address must not change with
        // the language of the page that registered it.
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $this->idShop);
        $link = \Context::getContext()->link ?? new \Link();

        $params = \Shop::isFeatureActive() ? ['scope' => $this->scope->slug()] : [];

        return (string) $link->getModuleLink('wajub', 'webhook', $params, true, $idLang, $this->idShop);
    }

    public function cronUrl(string $token): string
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $this->idShop);
        $link = \Context::getContext()->link ?? new \Link();

        return (string) $link->getModuleLink('wajub', 'cron', ['token' => $token], true, $idLang, $this->idShop);
    }

    public function isProduction(): bool
    {
        return !(defined('_PS_MODE_DEV_') && _PS_MODE_DEV_);
    }

    public function returnsOverHttps(): bool
    {
        // As PrestaShop's Link builds a front office address when it is not
        // told which scheme (Link::getBaseLink()), in the shop the payer is
        // on: HTTPS once SSL is on, and, on PrestaShop 8, on all pages too.
        // PrestaShop 9 reads PS_SSL_ENABLED alone: it has no
        // PS_SSL_ENABLED_EVERYWHERE any more, which would read as off.
        $allPages = $this->hasSslOnAllPages();
        foreach ($this->openShopIds() as $idShop) {
            if (!\Configuration::get('PS_SSL_ENABLED', null, null, $idShop)
                || ($allPages && !\Configuration::get('PS_SSL_ENABLED_EVERYWHERE', null, null, $idShop))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The shops of the scope that payers can reach: a shop switched off in
     * Advanced Parameters > Multistore takes no payment, and is not judged.
     *
     * @return list<int>
     */
    private function openShopIds(): array
    {
        if ($this->scope->idShop !== null || !\Shop::isFeatureActive()) {
            return [$this->idShop];
        }
        $open = array_values(array_map('intval', (array) \Shop::getShops(true, $this->scope->idShopGroup, true)));

        return $open !== [] ? $open : [$this->idShop];
    }

    public function hasSslOnAllPages(): bool
    {
        return version_compare($this->prestashopVersion(), '9.0.0', '<');
    }

    public function emptyPaymentPreferences(): array
    {
        $idModule = (int) \Module::getModuleIdByName('wajub');
        if ($idModule <= 0) {
            return [];
        }
        $prefix = _DB_PREFIX_;
        $module = ' WHERE m.`id_module` = ' . $idModule;
        $shop = ' AND m.`id_shop` = ' . (int) $this->idShop;
        // As Hook::getHookModuleExecList() filters the payment modules: a
        // currency in any shop (or every currency, -1 and -2), the others in
        // the shop. It filters by carrier only a cart that has one: a shop
        // with no carrier on (virtual products only) offers Wajub whatever
        // is ticked, and is never told otherwise.
        $row = \Db::getInstance()->getRow(
            'SELECT'
            . ' (SELECT COUNT(*) FROM `' . $prefix . 'module_currency` m LEFT JOIN `' . $prefix . 'currency` c ON c.`id_currency` = m.`id_currency`' . $module
            . ' AND (m.`id_currency` IN (-1, -2) OR (c.`active` = 1 AND c.`deleted` = 0))) AS `currencies`,'
            . ' (SELECT COUNT(*) FROM `' . $prefix . 'module_country` m INNER JOIN `' . $prefix . 'country` c ON c.`id_country` = m.`id_country`' . $module . $shop . ' AND c.`active` = 1) AS `countries`,'
            . ' (SELECT COUNT(*) FROM `' . $prefix . 'module_group` m' . $module . $shop . ') AS `groups`,'
            . ' (SELECT COUNT(*) FROM `' . $prefix . 'module_carrier` m INNER JOIN `' . $prefix . 'carrier` c ON c.`id_reference` = m.`id_reference`' . $module . $shop . ' AND c.`active` = 1 AND c.`deleted` = 0) AS `carriers`,'
            . ' (SELECT COUNT(*) FROM `' . $prefix . 'carrier` c INNER JOIN `' . $prefix . 'carrier_shop` cs ON cs.`id_carrier` = c.`id_carrier` AND cs.`id_shop` = ' . (int) $this->idShop . ' WHERE c.`active` = 1 AND c.`deleted` = 0) AS `shop_carriers`',
            false
        );
        if (!is_array($row)) {
            return [];
        }
        $empty = [];
        foreach (['currencies', 'countries', 'groups', 'carriers'] as $list) {
            if ((int) ($row[$list] ?? 1) === 0 && ($list !== 'carriers' || (int) ($row['shop_carriers'] ?? 0) > 0)) {
                $empty[] = $list;
            }
        }

        return $empty;
    }

    public function moduleVersion(): string
    {
        return $this->moduleVersion;
    }

    public function prestashopVersion(): string
    {
        return defined('_PS_VERSION_') ? (string) _PS_VERSION_ : '';
    }
}
