<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Checkout;

use Wajub\PrestaShop\Order\OrderView;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The storefront's pages an order's payment leads to, and the payer's cart:
 * PrestaShop's (PrestaShopStorefront), or addresses made up in the tests.
 */
interface Storefront
{
    /** PrestaShop's order confirmation page of the order, for its customer. */
    public function confirmation(OrderView $order): string;

    /** Where the pages that follow the order ask how its payment stands (controllers/front/status.php), signed for it. */
    public function status(OrderView $order): string;

    /** Where Wajub sends the payer back (controllers/front/return.php), signed for the order. */
    public function returnUrl(OrderView $order): string;

    /** Where the payer leaves the order's payment, to pay another way (controllers/front/release.php). */
    public function release(OrderView $order): string;

    /** Where the payer pays what is left of the order (controllers/front/pay.php). */
    public function pay(OrderView $order): string;

    /** The order's token, which status(), returnUrl() and release() carry. */
    public function orderToken(OrderView $order): string;

    /** The checkout. */
    public function checkout(): string;

    /**
     * Whether restoreCart() can put the order's cart in this visitor's
     * session: theirs is the order's customer's. PrestaShop signs a guest
     * out on the order confirmation page, and a link can be opened in
     * another browser.
     */
    public function canRestoreCart(OrderView $order): bool;

    /**
     * Puts the order's cart back in the payer's session as a new cart, the
     * same products and addresses, for them to place the order again, with
     * another method or the same one. True when it did: false when the copy
     * could not be made, or the session is not the order's customer's.
     */
    public function restoreCart(OrderView $order): bool;
}
