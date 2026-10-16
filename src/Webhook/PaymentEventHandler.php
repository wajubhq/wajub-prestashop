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

use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The webhooks of payments: what settles an order whose payer never came
 * back (a Mobile Money approval on their phone, a closed tab), and what
 * tells the shop of an earlier attempt that went through late. A port of
 * the WooCommerce parts of the WordPress plugin's Webhooks\Handler.
 *
 * Only a payment the shop started for an order (its `wajub_payment` row)
 * settles it: the metadata a payment carries is no proof, and another shop
 * on the same Wajub account numbers its orders alike. The order is settled
 * with the services of its own shop (its key, its environment), whatever
 * shop's address received the delivery, and under the order's lock: the
 * payer's return may read the same payment at the same moment, and only one
 * of them counts it. A delivery signed in one environment never settles a
 * payment made in the other.
 *
 * A delivery repeated, or one of a payment counted already, changes
 * nothing; one of a payment that went through on an order paid already
 * tells the merchant they were paid twice.
 *
 * A delivery acts as a read of the payment would (the payer's return, the
 * status checks, "Check status": Order\Settlement), with the same states and
 * notes; one whose payment says no status is named by its event
 * (`payment.expired` is an expired payment). The events of a payment paid
 * in instalments (`payment.split.*`, each with the whole payment, `partial`
 * between two instalments) keep where it stands, so that the order is never
 * given back while money is being taken for it.
 */
final class PaymentEventHandler implements EventHandler
{
    /** Events that end a payment unpaid. */
    private const UNPAID = [
        'payment.failed' => 'failed',
        'payment.rejected' => 'failed',
        'payment.canceled' => 'cancelled',
        'payment.cancelled' => 'cancelled',
        'payment.abandoned' => 'abandoned',
        'payment.expired' => 'expired',
    ];

    /** Seconds a delivery waits for the payer's return to finish with the order. */
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

    /** Events that say where a payment stands without ending it: kept on the payment. */
    private const UNDER_WAY = ['payment.processing', 'payment.partial', 'payment.split.processing', 'payment.split.succeeded', 'payment.split.failed'];

    public function handles(string $type): bool
    {
        return $type === 'payment.succeeded' || in_array($type, self::UNDER_WAY, true) || isset(self::UNPAID[$type]);
    }

    public function handle(Event $event): void
    {
        $data = $event->data;
        $paymentId = Ledger::idOf($data);
        $record = $paymentId !== '' ? $this->payments->find($paymentId) : null;
        if ($record === null || $record->idOrder === 0) {
            // Not a payment of this shop's orders: another shop on the same
            // account, a payment link, a payment prepared and never placed.
            $this->logger->info('Webhook payment of no order of this shop', ['event' => $event->id, 'payment' => $paymentId]);

            return;
        }
        if ($record->environment !== $event->environment) {
            $this->logger->info('Webhook signed in another environment than its payment: not acted on', ['event' => $event->id, 'payment' => $paymentId]);

            return;
        }

        // Counted in the environment of the secret that signed it, when the
        // payment does not say.
        if (!array_key_exists('sandbox', $data) || $data['sandbox'] === null) {
            $data['sandbox'] = $event->environment === 'sandbox';
        }

        $services = ($this->servicesFor)($record->idShop);
        $lock = $services->lock();
        $name = 'order:' . $services->orders()->leadId($record->idOrder);
        if (!$lock->acquire($name, self::LOCK_WAIT)) {
            // The payer's return holds it: Wajub sends this delivery again.
            throw new \RuntimeException('The order is being settled by another request');
        }
        try {
            $this->settle($services, $event, $paymentId, $data);
        } finally {
            $lock->release($name);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function settle(Services $services, Event $event, string $paymentId, array $data): void
    {
        // Read again under the lock: the payer's return may have changed it.
        $record = $services->payments()->find($paymentId);
        $order = $record !== null ? $services->orders()->find($record->idOrder) : null;
        if ($record === null || $order === null) {
            $this->logger->info('Webhook payment of an order the shop no longer has', ['event' => $event->id, 'payment' => $paymentId]);

            return;
        }

        $settlement = $services->settlement();
        $status = strtolower(Text::code($data['status'] ?? '', 32));
        $current = $services->payments()->current($order->id);
        $earlier = $current !== null && $current->paymentId !== $record->paymentId;

        if ($event->type === 'payment.succeeded' || Client::isSucceededStatus($status)) {
            if ($order->paid) {
                // A late or repeated delivery of a payment that paid it changes
                // nothing; any other went through on top of the order's total.
                if (!$record->isCounted()) {
                    $settlement->remember($record, $data);
                    $settlement->noteSecondPayment($order, $record, $data);
                }

                return;
            }
            // An earlier attempt, not counted yet: what it still holds (its
            // refunds read first) decides whether it pays the order.
            if ($earlier && !$record->isCounted() && Ledger::sameCurrency($order, $data) && !$settlement->countLate($order, $record, $data)) {
                return;
            }
            $settlement->apply($order, $record, [
                'outcome' => Client::OUTCOME_SUCCEEDED,
                'status' => $status !== '' ? $status : 'succeeded',
                'transaction' => $data,
            ], Settlement::SEEN_NOW);

            return;
        }

        if (isset(self::UNPAID[$event->type]) || Client::isFailedStatus($status)) {
            if ($order->paid) {
                return;
            }
            if ($earlier) {
                // The payer started another payment since: the order is that one's.
                $settlement->remember($record, $data);
                $this->logger->info('Webhook failure of an earlier payment attempt left the order as it is', ['order' => $order->id, 'payment' => $paymentId]);

                return;
            }
            $settlement->apply($order, $record, [
                'outcome' => Client::OUTCOME_FAILED,
                'status' => $status !== '' ? $status : (self::UNPAID[$event->type] ?? 'failed'),
                'transaction' => $data,
            ]);

            return;
        }

        // Under way, or between two instalments: kept on the payment, so the
        // order is never given back while money is being taken for it.
        $settlement->remember($record, $data);
    }
}
