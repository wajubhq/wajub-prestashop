<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Webhook;

use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The environment the shop of an event takes payments in, for the webhook
 * (Receiver): the shop whose order the event's payment was started for
 * (its `wajub_payment` row), the one the handlers act on.
 *
 * In a multistore, the endpoint that receives a delivery is the one of the
 * scope whose key made the payment (`?scope=all`, `g2`, `s3`), which many
 * shops share; each of them may use another environment than that scope.
 * A shop in Sandbox that pays with the sandbox key of "All shops", which is
 * in Live, gets its test payments' deliveries through All shops' sandbox
 * endpoint: judged by All shops' environment, they were answered as
 * received and dropped, and the shop's orders never settled from them.
 */
final class EventShops
{
    /** @var PaymentStore */
    private $payments;

    /** @var callable(int): Services */
    private $servicesFor;

    /**
     * @param callable(int): Services $servicesFor the services of a shop (Wajub::servicesFor())
     */
    public function __construct(PaymentStore $payments, callable $servicesFor)
    {
        $this->payments = $payments;
        $this->servicesFor = $servicesFor;
    }

    /**
     * 'sandbox' or 'live': the environment the shop of the event's order
     * uses; null when the event is about no payment of an order of this
     * PrestaShop (another PrestaShop on the account, a payment link), which
     * the receiving scope then judges.
     */
    public function environmentOf(Event $event): ?string
    {
        $paymentId = str_starts_with($event->type, 'payment.')
            ? Ledger::idOf($event->data)
            // A refund or a dispute names its payment (Refund, Dispute handlers).
            : Text::code($event->data['transaction'] ?? $event->data['payment'] ?? '', 80);
        $record = $paymentId !== '' ? $this->payments->find($paymentId) : null;
        if ($record === null || $record->idOrder === 0 || $record->idShop <= 0) {
            return null;
        }

        return ($this->servicesFor)($record->idShop)->environment()->isLive() ? Settings::LIVE : Settings::SANDBOX;
    }
}
