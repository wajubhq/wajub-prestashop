<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Payment;

use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The names and descriptions a payment option has until the merchant writes
 * their own, in the language of the page: what the payer reads at
 * checkout, and what the settings show in place of an empty field.
 */
final class MethodTexts
{
    public static function defaultTitle(string $kind, Translator $t): string
    {
        switch ($kind) {
            case Methods::MOBILE_MONEY:
                return $t->trans('Mobile Money', [], 'Modules.Wajub.Shop');
            case Methods::CARD:
                return $t->trans('Card', [], 'Modules.Wajub.Shop');
            default:
                return $t->trans('Other payment methods', [], 'Modules.Wajub.Shop');
        }
    }

    /**
     * The name of the one option there is while Mobile Money and Card are
     * both off: Wajub's hosted page, with everything the account offers.
     */
    public static function everythingTitle(Translator $t): string
    {
        return $t->trans('Mobile Money or card', [], 'Modules.Wajub.Shop');
    }

    public static function everythingDescription(Translator $t): string
    {
        return $t->trans('Pay with Mobile Money, card, or other methods via Wajub.', [], 'Modules.Wajub.Shop');
    }

    public static function defaultDescription(string $kind, Translator $t): string
    {
        switch ($kind) {
            case Methods::MOBILE_MONEY:
                return $t->trans('Pay with your Mobile Money account: you approve the payment on your phone.', [], 'Modules.Wajub.Shop');
            case Methods::CARD:
                return $t->trans('Pay by Visa or Mastercard.', [], 'Modules.Wajub.Shop');
            default:
                return $t->trans('Pay with another payment method offered by Wajub.', [], 'Modules.Wajub.Shop');
        }
    }
}
