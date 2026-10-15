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

use Wajub\PrestaShop\Support\Phone;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A PrestaShop cart as the checkout reads it (CartView): its total with
 * taxes and delivery, its currency, the country of its invoice address, and
 * the payer: the customer's e-mail and name, and the phone of the invoice
 * address (or the delivery one) in its international form, with the
 * country code of that address's country.
 */
final class PrestaShopCarts
{
    public static function view(\Cart $cart): ?CartView
    {
        if (!\Validate::isLoadedObject($cart)) {
            return null;
        }

        $currency = new \Currency((int) $cart->id_currency);
        $customer = new \Customer((int) $cart->id_customer);
        $invoice = new \Address((int) ($cart->id_address_invoice ?: $cart->id_address_delivery));
        $delivery = new \Address((int) ($cart->id_address_delivery ?: $cart->id_address_invoice));

        $country = '';
        $phone = '';
        foreach ([$invoice, $delivery] as $address) {
            if (!\Validate::isLoadedObject($address)) {
                continue;
            }
            $countryObject = new \Country((int) $address->id_country);
            if ($country === '') {
                $country = strtoupper((string) $countryObject->iso_code);
            }
            $raw = trim((string) ($address->phone_mobile ?: $address->phone));
            if ($phone === '' && $raw !== '') {
                $phone = Phone::international($raw, (int) $countryObject->call_prefix);
            }
        }

        $name = '';
        if (\Validate::isLoadedObject($customer)) {
            $name = Text::line(trim($customer->firstname . ' ' . $customer->lastname), 255);
        }

        return new CartView(
            (int) $cart->id,
            (int) $cart->id_shop,
            (int) $cart->id_customer,
            (int) $cart->id_lang,
            strtoupper((string) $currency->iso_code),
            (float) $cart->getOrderTotal(true, \Cart::BOTH),
            $country,
            \Validate::isLoadedObject($customer) ? (string) $customer->email : '',
            $phone,
            $name
        );
    }
}
