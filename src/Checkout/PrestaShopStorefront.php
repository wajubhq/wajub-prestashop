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
 * The Storefront on PrestaShop's links and carts. Each address is the
 * order's shop's, in the order's language: a webhook or a return reaches
 * the shop that placed it, whatever page builds the link.
 */
final class PrestaShopStorefront implements Storefront
{
    /** @var Tokens */
    private $tokens;

    public function __construct(Tokens $tokens)
    {
        $this->tokens = $tokens;
    }

    public function confirmation(OrderView $order): string
    {
        return (string) $this->link()->getPageLink('order-confirmation', null, $order->idLang ?: null, [
            'id_cart' => $order->idCart,
            'id_module' => (int) \Module::getModuleIdByName('wajub'),
            'id_order' => $order->id,
            'key' => $order->secureKey,
        ], false, $order->idShop ?: null);
    }

    public function status(OrderView $order): string
    {
        return $this->moduleLink('status', $order);
    }

    public function returnUrl(OrderView $order): string
    {
        return $this->moduleLink('return', $order);
    }

    public function release(OrderView $order): string
    {
        return $this->moduleLink('release', $order);
    }

    public function pay(OrderView $order): string
    {
        return $this->moduleLink('pay', $order);
    }

    public function orderToken(OrderView $order): string
    {
        return $this->tokens->order($order->id, $order->secureKey);
    }

    public function checkout(): string
    {
        return (string) $this->link()->getPageLink('order', null);
    }

    public function canRestoreCart(OrderView $order): bool
    {
        $cookie = \Context::getContext()->cookie;

        return $order->idCustomer > 0 && $cookie instanceof \Cookie && (int) $cookie->id_customer === $order->idCustomer;
    }

    public function restoreCart(OrderView $order): bool
    {
        if (!$this->canRestoreCart($order)) {
            return false;
        }
        $cart = new \Cart($order->idCart);
        if (!\Validate::isLoadedObject($cart)) {
            return false;
        }
        $duplicated = $cart->duplicate();
        $copy = is_array($duplicated) ? ($duplicated['cart'] ?? null) : null;
        if (!$copy instanceof \Cart || !\Validate::isLoadedObject($copy)) {
            return false;
        }

        // Where the payer was in the checkout: PrestaShop resumes there when
        // the cart is the same (CartChecksum), which it is.
        $db = \Db::getInstance();
        $progress = $db->getValue('SELECT `checkout_session_data` FROM `' . _DB_PREFIX_ . 'cart` WHERE `id_cart` = ' . (int) $cart->id, false);
        if (is_string($progress) && $progress !== '') {
            $db->update('cart', ['checkout_session_data' => pSQL($progress, true)], '`id_cart` = ' . (int) $copy->id, 1, false, false);
        }
        // The vouchers it had, which duplicate() leaves out; PrestaShop
        // takes back any no longer valid as the checkout shows the cart.
        foreach ($cart->getCartRules() as $rule) {
            $copy->addCartRule((int) $rule['id_cart_rule']);
        }

        $context = \Context::getContext();
        if (!$context->cookie instanceof \Cookie || (int) $context->cookie->id_customer !== (int) $copy->id_customer) {
            return false;
        }
        $context->cookie->__set('id_cart', (int) $copy->id);
        $context->cookie->write();
        $context->cart = $copy;

        return true;
    }

    private function moduleLink(string $controller, OrderView $order): string
    {
        return (string) $this->link()->getModuleLink('wajub', $controller, [
            'id_order' => $order->id,
            'token' => $this->orderToken($order),
        ], null, $order->idLang ?: null, $order->idShop ?: null);
    }

    private function link(): \Link
    {
        $link = \Context::getContext()->link;

        return $link instanceof \Link ? $link : new \Link();
    }
}
