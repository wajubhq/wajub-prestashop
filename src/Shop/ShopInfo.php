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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the module needs to know about the shop it runs for: what it calls
 * itself in Wajub, where Wajub reaches it, and whether it is the shop the
 * public uses or a copy of it.
 */
interface ShopInfo
{
    /** The shop the settings page and the webhook are for: the shop in context, or the default shop for a group or all shops. */
    public function id(): int;

    public function name(): string;

    /** The shop's address, such as "https://boutique.cm/" or "https://example.com/shop/". */
    public function baseUrl(): string;

    /**
     * The shops the settings of this scope are for: the shop itself, the
     * shops of a group, or every shop of the PrestaShop.
     *
     * @return list<int>
     */
    public function shopIds(): array;

    /** Where Wajub posts its webhooks for this shop (controllers/front/webhook.php). */
    public function webhookUrl(): string;

    /** Where the merchant's scheduler runs the module's task (controllers/front/cron.php), with its token. */
    public function cronUrl(string $token): string;

    /**
     * Whether this is a shop the public uses: not one in debug mode, which a
     * copy for development or testing is. A copy never deletes the key it
     * shares with the shop it was copied from, and never acts on live
     * deliveries meant for that shop.
     */
    public function isProduction(): bool;

    public function moduleVersion(): string;

    public function prestashopVersion(): string;
}
