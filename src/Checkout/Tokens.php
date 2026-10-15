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
 * The tokens the storefront's requests carry, signed with the shop's own
 * secret (_COOKIE_KEY_): another site cannot make one, and each is good for
 * one cart or one order only.
 *
 * - checkout: the payment box's (prepare, place the order), for the payer's
 *   cart and customer; a page from another cart, or another visitor, has
 *   none that works.
 * - order: the pages that follow an order once placed (its status, the
 *   payer's return from Wajub, leaving the payment, paying the rest), for
 *   that order and its customer's secure key, which PrestaShop's own
 *   confirmation page asks for.
 * - cron: the address the merchant's scheduler calls to read again the
 *   payments that could not count, and cancel abandoned orders
 *   (controllers/front/cron.php); the same for every shop of the
 *   PrestaShop, and shown only in the back office.
 */
final class Tokens
{
    /** @var string */
    private $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function checkout(int $idCart, int $idCustomer): string
    {
        return $this->sign('wajub-checkout|' . $idCart . '|' . $idCustomer);
    }

    public function order(int $idOrder, string $secureKey): string
    {
        return $this->sign('wajub-order|' . $idOrder . '|' . $secureKey);
    }

    public function cron(): string
    {
        return $this->sign('wajub-cron');
    }

    public static function matches(string $expected, string $given): bool
    {
        return $given !== '' && hash_equals($expected, $given);
    }

    private function sign(string $what): string
    {
        return substr(hash_hmac('sha256', $what, $this->secret), 0, 40);
    }
}
