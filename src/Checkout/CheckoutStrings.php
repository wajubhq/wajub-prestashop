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

use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Support\CustomerMessage;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Every word the payer reads around Wajub's payment form and on the order
 * confirmation page, in PHP so each is translated with the module: the
 * scripts receive them with their configuration and carry none of their
 * own. A port of the WordPress plugin's FieldsStrings::checkout() and its
 * order status words.
 */
final class CheckoutStrings
{
    /**
     * The words of the payment box. What depends on the kind of method
     * (`mobile_money` or `card`) fits it; any other kind gets words that fit
     * both.
     *
     * @return array<string, string>
     */
    public static function fields(string $kind, Translator $t): array
    {
        $mobileMoney = $kind === Methods::MOBILE_MONEY;
        $card = $kind === Methods::CARD;

        return [
            // Read by screen readers around the payment form.
            'legend' => $mobileMoney ? $t->trans('Mobile Money payment details', [], 'Modules.Wajub.Shop') : ($card ? $t->trans('Card payment details', [], 'Modules.Wajub.Shop') : $t->trans('Payment details', [], 'Modules.Wajub.Shop')),
            // The title of the payment form's frame.
            'frameTitle' => $mobileMoney ? $t->trans('Secure Mobile Money payment form', [], 'Modules.Wajub.Shop') : ($card ? $t->trans('Secure card payment form', [], 'Modules.Wajub.Shop') : $t->trans('Secure payment form', [], 'Modules.Wajub.Shop')),
            // "Place order" with the form not filled in.
            'incomplete' => $mobileMoney ? $t->trans('Enter the Mobile Money number to pay with.', [], 'Modules.Wajub.Shop') : ($card ? $t->trans('Enter your card details to pay.', [], 'Modules.Wajub.Shop') : $t->trans('Complete your payment details to pay.', [], 'Modules.Wajub.Shop')),
            // Once the order is placed, while the payment goes through.
            'paying' => $mobileMoney
                ? $t->trans('Approve the payment on your phone: your operator sends you a request.', [], 'Modules.Wajub.Shop')
                : $t->trans('Complete the payment in the form above.', [], 'Modules.Wajub.Shop'),
            'loading' => $t->trans('Loading the secure payment form…', [], 'Modules.Wajub.Shop'),
            'placing' => $t->trans('Placing your order…', [], 'Modules.Wajub.Shop'),
            'hostedHint' => $t->trans('You will pay on a secure Wajub page, then come back here.', [], 'Modules.Wajub.Shop'),
            // The payment form cannot load here (blocked, refused, too slow).
            'fallback' => $t->trans('The payment form could not be loaded here. Place your order to pay on a secure Wajub page.', [], 'Modules.Wajub.Shop'),
            'unavailable' => $t->trans('This payment method cannot be used for this order. Choose another payment method.', [], 'Modules.Wajub.Shop'),
            'amountOutOfRange' => $t->trans('This amount cannot be paid with this payment method. Choose another payment method.', [], 'Modules.Wajub.Shop'),
            'notConfigured' => CustomerMessage::shopUnavailable($t),
            'temporarilyUnavailable' => CustomerMessage::temporarilyUnavailable($t),
            'refreshed' => self::refreshed($t),
            'checking' => $t->trans('Checking your payment…', [], 'Modules.Wajub.Shop'),
            'paid' => $t->trans('Payment received. Taking you to your order…', [], 'Modules.Wajub.Shop'),
            // A payment Wajub is still taking when the payer leaves the step:
            // the order's confirmation page follows it, rather than a second attempt.
            'confirming' => $t->trans('Your payment is still being confirmed. Taking you to your order…', [], 'Modules.Wajub.Shop'),
            // A declined attempt: the same form can try again.
            'declined' => $t->trans('The payment did not go through. Check the details and try again, or use another payment method.', [], 'Modules.Wajub.Shop'),
            // The form was submitted and nothing moved: a field it found invalid, without a word.
            'nothingHappened' => $t->trans('Nothing happened? Check the details above, then Try again.', [], 'Modules.Wajub.Shop'),
            'cancelled' => $t->trans('Payment not completed. You can try again or choose another payment method.', [], 'Modules.Wajub.Shop'),
            'expired' => $t->trans('Your payment session expired. Place your order again to start a new payment.', [], 'Modules.Wajub.Shop'),
            'failed' => $t->trans('The payment was declined or failed. Please try again or choose another payment method.', [], 'Modules.Wajub.Shop'),
            // The payer leaves: the order placed is given back as a cart, for another way to pay.
            'leaving' => $t->trans('Getting your cart back…', [], 'Modules.Wajub.Shop'),
            'redirecting' => $t->trans('Taking you to the secure Wajub payment page…', [], 'Modules.Wajub.Shop'),
            'tryAgain' => $t->trans('Try again', [], 'Modules.Wajub.Shop'),
            'useAnother' => $t->trans('Use another payment method', [], 'Modules.Wajub.Shop'),
            // The same payment on Wajub's own page, when the form cannot finish it.
            'continueHosted' => $t->trans('Continue on the secure Wajub page', [], 'Modules.Wajub.Shop'),
            'startFailed' => $t->trans('Your payment could not be started. Please try again in a moment.', [], 'Modules.Wajub.Shop'),
        ];
    }

    /**
     * What the payer reads when the order cannot be paid with the payment the
     * form holds (another tab replaced it, the shop's keys changed, it is
     * about to expire, or Wajub no longer takes it): the form loads a new
     * one, and nothing was charged.
     */
    public static function refreshed(Translator $t): string
    {
        return $t->trans('Your payment details were refreshed. Check them, then place your order again.', [], 'Modules.Wajub.Shop');
    }

    /** The badge that tells a payer no real money moves, while the shop takes test payments. */
    public static function testMode(Translator $t): string
    {
        return $t->trans('Test mode — no real money will be charged', [], 'Modules.Wajub.Shop');
    }

    public static function secured(Translator $t): string
    {
        return $t->trans('Secured by Wajub', [], 'Modules.Wajub.Shop');
    }

    /**
     * What the payer reads on the checkout when the payment ended unpaid and
     * the order placed was given back as a cart (Checkout\Release).
     */
    public static function released(string $reason, Translator $t): string
    {
        switch ($reason) {
            case 'expired':
                return $t->trans('Your payment session expired before it was completed. Your cart is back: place your order again to pay.', [], 'Modules.Wajub.Shop');
            case 'failed':
                return $t->trans('The payment was declined or failed. Your cart is back: try again or choose another payment method.', [], 'Modules.Wajub.Shop');
            case 'unavailable':
                return $t->trans('This payment method cannot be used for this order. Your cart is back: choose another payment method.', [], 'Modules.Wajub.Shop');
            case 'switch':
                return $t->trans('Your cart is back: choose another payment method.', [], 'Modules.Wajub.Shop');
            default:
                return $t->trans('You cancelled the payment. Your cart is back: you can try again or choose another payment method.', [], 'Modules.Wajub.Shop');
        }
    }

    /**
     * What the payer reads on the checkout when the payment ended unpaid but
     * the order's cart could not be put back in their session: signed out
     * (PrestaShop signs a guest out on the order confirmation page), in
     * another browser, or a cart that could not be copied.
     */
    public static function notRestored(Translator $t): string
    {
        return $t->trans('Your payment was not completed, and your cart could not be restored here. Add the products to your cart again to place your order.', [], 'Modules.Wajub.Shop');
    }

    /**
     * The words of the order confirmation page's status panel (order-status.js).
     *
     * @return array<string, string>
     */
    public static function orderStatus(Translator $t): array
    {
        return [
            'waiting' => $t->trans('Waiting for the payment to be confirmed…', [], 'Modules.Wajub.Shop'),
            'checking' => $t->trans('Checking…', [], 'Modules.Wajub.Shop'),
            'paidTitle' => $t->trans('Payment received', [], 'Modules.Wajub.Shop'),
            'paidText' => $t->trans('Thank you! Updating your order…', [], 'Modules.Wajub.Shop'),
            'failedTitle' => $t->trans('The payment did not go through', [], 'Modules.Wajub.Shop'),
            'cancelledTitle' => $t->trans('Payment cancelled', [], 'Modules.Wajub.Shop'),
            'expiredTitle' => $t->trans('Payment session expired', [], 'Modules.Wajub.Shop'),
            'failedText' => $t->trans('Your order is saved, but it is not paid.', [], 'Modules.Wajub.Shop'),
            'partialTitle' => $t->trans('Part of your order is paid', [], 'Modules.Wajub.Shop'),
            'cancelledOrderTitle' => $t->trans('Order cancelled', [], 'Modules.Wajub.Shop'),
            'retry' => $t->trans('Try again', [], 'Modules.Wajub.Shop'),
            'payRest' => $t->trans('Pay the rest', [], 'Modules.Wajub.Shop'),
            'timeoutTitle' => $t->trans('Still waiting for the payment', [], 'Modules.Wajub.Shop'),
            'timeoutText' => $t->trans('We have not received the confirmation yet. If you approved the payment, it may take a few more minutes: check again, or contact the shop with your order reference.', [], 'Modules.Wajub.Shop'),
            'checkAgain' => $t->trans('Check again', [], 'Modules.Wajub.Shop'),
            'copied' => $t->trans('Copied', [], 'Modules.Wajub.Shop'),
            'copyFailed' => $t->trans('Copy it by hand', [], 'Modules.Wajub.Shop'),
        ];
    }
}
