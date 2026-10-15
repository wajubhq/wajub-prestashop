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

use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The webhooks of disputes (dispute.created, .updated, .won, .lost): a
 * chargeback on a payment of an order is a business risk the merchant must
 * see where they look at the order, so each is noted on it, once per
 * dispute and event. A port of the WooCommerce part of the WordPress
 * plugin's Webhooks\Handler::handleDisputeEvent(); the merchant answers the
 * dispute in the Wajub Dashboard.
 *
 * A dispute names its payment in `transaction`; only a payment the shop
 * started for an order (its `wajub_payment` row) binds it to that order,
 * never the dispute's metadata. A delivery signed in one environment never
 * touches a payment of the other.
 */
final class DisputeEventHandler implements EventHandler
{
    /** @var PaymentStore */
    private $payments;

    /** @var callable(int): Services */
    private $servicesFor;

    /** @var Logger */
    private $logger;

    /**
     * @param callable(int): Services $servicesFor the services of a shop (Wajub::servicesFor())
     */
    public function __construct(PaymentStore $payments, callable $servicesFor, Logger $logger)
    {
        $this->payments = $payments;
        $this->servicesFor = $servicesFor;
        $this->logger = $logger;
    }

    public function handles(string $type): bool
    {
        return str_starts_with($type, 'dispute.');
    }

    public function handle(Event $event): void
    {
        $data = $event->data;
        $paymentId = Text::code($data['transaction'] ?? $data['payment'] ?? '', 80);
        $record = $paymentId !== '' ? $this->payments->find($paymentId) : null;
        if ($record === null || $record->idOrder === 0) {
            $this->logger->info('Webhook dispute of a payment of no order of this shop', ['event' => $event->id, 'payment' => $paymentId]);

            return;
        }
        if ($record->environment !== $event->environment) {
            $this->logger->info('Webhook dispute signed in another environment than its payment: not acted on', ['event' => $event->id, 'payment' => $paymentId]);

            return;
        }

        $services = ($this->servicesFor)($record->idShop);
        $order = $services->orders()->find($record->idOrder);
        if ($order === null) {
            return;
        }
        $t = $services->translator();
        $disputeId = Text::code($data['id'] ?? '', 80);
        $currency = strtoupper(Text::code($data['currency'] ?? '', 3)) ?: $order->currency;
        $reason = Text::line($data['reason'] ?? '', 200);
        // Named in the note: a real chargeback must never read like a test.
        $environment = $record->environment === 'live'
            ? $t->trans('Live', [], 'Modules.Wajub.Admin')
            : $t->trans('Sandbox', [], 'Modules.Wajub.Admin');
        $services->orders()->note($order, 'dispute_' . md5($disputeId . '|' . $event->type), $t->trans(
            'Wajub dispute event: %event% on payment %payment% (%environment%). Reason: %reason%. Amount: %amount%. Answer it from the Wajub Dashboard.',
            [
                '%event%' => Text::code($event->type, 40),
                '%payment%' => $paymentId,
                '%environment%' => $environment,
                '%reason%' => $reason !== '' ? $reason : '—',
                '%amount%' => $services->money()->format(is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : 0.0, $currency),
            ],
            'Modules.Wajub.Admin'
        ));
        $this->logger->info('Webhook dispute noted on its order', ['event' => $event->id, 'type' => $event->type, 'order' => $order->id, 'dispute' => $disputeId]);
    }
}
