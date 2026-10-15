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
 * The webhooks of refunds: one made in the Wajub Dashboard, recorded on the
 * order its payment was made for, and the progress of one the merchant made
 * from a credit slip (Refund\RefundSync::webhook()). A refund names its
 * payment in `transaction`; only a payment the shop started for an order
 * (its `wajub_payment` row) binds it to that order, never the refund's
 * metadata, which another shop on the same account writes alike.
 *
 * Acted on with the services of the order's shop and under the order's
 * lock, as the payments' webhooks are: the back office may be refunding the
 * same order. A delivery signed in one environment never touches a payment
 * of the other: refunds of one environment are never recorded on an order
 * paid in the other.
 */
final class RefundEventHandler implements EventHandler
{
    /** Seconds a delivery waits for another request to finish with the order. */
    private const LOCK_WAIT = 8;

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
        return str_starts_with($type, 'refund.');
    }

    public function handle(Event $event): void
    {
        $data = $event->data;
        $paymentId = Text::code($data['transaction'] ?? $data['payment'] ?? '', 80);
        $record = $paymentId !== '' ? $this->payments->find($paymentId) : null;
        if ($record === null || $record->idOrder === 0) {
            $this->logger->info('Webhook refund of a payment of no order of this shop', ['event' => $event->id, 'payment' => $paymentId]);

            return;
        }
        if ($record->environment !== $event->environment) {
            $this->logger->info('Webhook refund signed in another environment than its payment: not acted on', ['event' => $event->id, 'payment' => $paymentId]);

            return;
        }

        $services = ($this->servicesFor)($record->idShop);
        $lock = $services->lock();
        $name = 'order:' . $record->idOrder;
        if (!$lock->acquire($name, self::LOCK_WAIT)) {
            // Another request holds it: Wajub sends this delivery again.
            throw new \RuntimeException('The order is being updated by another request');
        }
        try {
            // Read again under the lock.
            $record = $services->payments()->find($paymentId);
            $order = $record !== null ? $services->orders()->find($record->idOrder) : null;
            if ($record === null || $order === null) {
                $this->logger->info('Webhook refund of an order the shop no longer has', ['event' => $event->id, 'payment' => $paymentId]);

                return;
            }
            $services->refundSync()->webhook($order, $record, $event->type, $data);
        } finally {
            $lock->release($name);
        }
    }
}
