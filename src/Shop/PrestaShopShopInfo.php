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

    public function moduleVersion(): string
    {
        return $this->moduleVersion;
    }

    public function prestashopVersion(): string
    {
        return defined('_PS_VERSION_') ? (string) _PS_VERSION_ : '';
    }
}
