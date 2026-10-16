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

    /**
     * Whether Wajub can send payers back to the shops of this scope that
     * are on (one switched off takes no payment): their addresses are HTTPS, as PrestaShop builds the module's return address
     * (Checkout\PrestaShopStorefront): SSL turned on and, where PrestaShop
     * has that setting (hasSslOnAllPages()), on all pages too. In Live,
     * Wajub returns payers to HTTPS addresses only
     * (Checkout\PreparedPayments::callback()).
     */
    public function returnsOverHttps(): bool;

    /**
     * Whether PrestaShop has the "SSL on all pages" setting
     * (PS_SSL_ENABLED_EVERYWHERE), which its addresses need besides SSL to be
     * HTTPS: PrestaShop 8. PrestaShop 9 dropped it, setting and value: SSL
     * alone makes every address HTTPS there, and the back office asks for
     * nothing more.
     */
    public function hasSslOnAllPages(): bool;

    /**
     * The lists of Payment > Preferences in which nothing is ticked for the
     * module, for the shop the scope speaks for: 'currencies', 'countries',
     * 'groups', 'carriers' (only what is active counts; carriers only in a
     * shop that has one on, as PrestaShop filters by carrier only a cart
     * that has one). PrestaShop offers the module to no customer then,
     * whatever its settings say.
     *
     * @return list<string>
     */
    public function emptyPaymentPreferences(): array;

    public function moduleVersion(): string;

    public function prestashopVersion(): string;
}
