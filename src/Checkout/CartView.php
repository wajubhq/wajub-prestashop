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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the checkout needs of the payer's cart: what a payment of it asks
 * (its total, its currency), where the payer is (the invoice address's
 * country), and who pays (their e-mail, their phone in its international
 * form, their name). Read by the controllers from PrestaShop's cart
 * (PrestaShopCarts), built by hand in the tests.
 */
final class CartView
{
    public function __construct(
        public readonly int $id,
        public readonly int $idShop,
        public readonly int $idCustomer,
        public readonly int $idLang,
        public readonly string $currency,
        public readonly float $total,
        public readonly string $country = '',
        public readonly string $email = '',
        public readonly string $phone = '',
        public readonly string $name = '',
    ) {
    }

    /** Something to pay, in a currency: a cart a payment can be made for. */
    public function payable(): bool
    {
        return $this->id > 0 && $this->total > 0 && preg_match('/^[A-Z]{3}$/', $this->currency) === 1;
    }
}
