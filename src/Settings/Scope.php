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
 * Which shops a setting is read and written for. A shop on its own, a group
 * of shops, or every shop (both ids null): what the back office's shop
 * selector says, and the shop of the page on the storefront. Without
 * multistore, every setting is the shop's one value, kept for all shops.
 */
final class Scope
{
    public function __construct(
        public readonly ?int $idShop,
        public readonly ?int $idShopGroup,
    ) {
    }

    public static function allShops(): self
    {
        return new self(null, null);
    }

    /**
     * The scope of one shop, whatever the context is: the shop of an order
     * or of a Wajub payment, which a webhook or the back office's order page
     * acts on while the context may be another shop, or all of them.
     */
    public static function ofShop(int $idShop): self
    {
        if (!\Shop::isFeatureActive()) {
            return self::allShops();
        }

        return new self($idShop, (int) \Shop::getGroupFromShop($idShop));
    }

    /**
     * The scope PrestaShop's context is in: the shop of a storefront page,
     * or the shop, group or "All shops" chosen in the back office.
     */
    public static function fromContext(): self
    {
        if (!\Shop::isFeatureActive()) {
            return self::allShops();
        }

        switch (\Shop::getContext()) {
            case \Shop::CONTEXT_SHOP:
                return new self((int) \Shop::getContextShopID(), (int) \Shop::getContextShopGroupID());
            case \Shop::CONTEXT_GROUP:
                return new self(null, (int) \Shop::getContextShopGroupID());
            default:
                return self::allShops();
        }
    }

    /**
     * The scope a slug names ('all', 'g2', 's3', as slug() writes it), or
     * null for anything else. Whether that shop or group exists is the
     * caller's to check: a slug comes from an address anyone can write
     * (the webhook's), or from the configuration table.
     */
    public static function fromSlug(string $slug): ?self
    {
        if ($slug === 'all') {
            return self::allShops();
        }
        if (preg_match('/^g([1-9][0-9]{0,9})$/', $slug, $match) === 1) {
            return new self(null, (int) $match[1]);
        }
        if (preg_match('/^s([1-9][0-9]{0,9})$/', $slug, $match) === 1) {
            $idShop = (int) $match[1];

            return new self($idShop, \Shop::isFeatureActive() ? (int) \Shop::getGroupFromShop($idShop) : null);
        }

        return null;
    }

    public function isShop(): bool
    {
        return $this->idShop !== null;
    }

    public function isAllShops(): bool
    {
        return $this->idShop === null && $this->idShopGroup === null;
    }

    public function isGroup(): bool
    {
        return $this->idShop === null && $this->idShopGroup !== null;
    }

    /**
     * The scopes a value of this scope comes from, its own first: a shop,
     * then its group, then all shops; a group, then all shops. Configuration
     * reads a value in that order.
     *
     * @return list<string> slugs
     */
    public function chain(): array
    {
        $chain = [];
        if ($this->idShop !== null) {
            $chain[] = 's' . $this->idShop;
        }
        if ($this->idShopGroup !== null) {
            $chain[] = 'g' . $this->idShopGroup;
        }
        $chain[] = 'all';

        return $chain;
    }

    /** A name for what is kept under this scope alone: 'all', 'g2', 's3'. */
    public function slug(): string
    {
        if ($this->idShop !== null) {
            return 's' . $this->idShop;
        }

        return $this->idShopGroup !== null ? 'g' . $this->idShopGroup : 'all';
    }
}
