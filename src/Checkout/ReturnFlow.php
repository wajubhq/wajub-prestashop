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

use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Order\ShopOrders;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The payer back from Wajub's hosted page (controllers/front/return.php,
 * the payment's `callback`): what the payment became is read from Wajub, by
 * the id the shop recorded for the order, never from the address, which the
 * payer could have written. A port of the WordPress plugin's
 * Gateway::handleCallback().
 *
 * - Paid, or part of it: settled as the webhook would, then the order's
 *   confirmation page, which says what is left to pay.
 * - Ended unpaid (cancelled, expired, refused): the order is given back as a
 *   cart (Release), and the payer is back at the checkout with a message.
 * - Not final yet, or Wajub did not answer: the confirmation page, which
 *   follows the payment until the webhook settles it.
 *
 * The caller holds the order's lock.
 */
final class ReturnFlow
{
    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var Settlement */
    private $settlement;

    /** @var Release */
    private $release;

    /** @var Storefront */
    private $storefront;

    /** @var Translator */
    private $t;

    public function __construct(ShopOrders $orders, PaymentStore $payments, Settlement $settlement, Release $release, Storefront $storefront, Translator $t)
    {
        $this->orders = $orders;
        $this->payments = $payments;
        $this->settlement = $settlement;
        $this->release = $release;
        $this->storefront = $storefront;
        $this->t = $t;
    }

    /**
     * Where the payer goes, with what they read there (`message`, `tone`
     * error or info), '' for nothing.
     *
     * @return array{url: string, message: string, tone: string}
     */
    public function handle(OrderView $order): array
    {
        $order = $this->orders->find($order->id) ?? $order;
        $confirmation = ['url' => $this->storefront->confirmation($order), 'message' => '', 'tone' => 'info'];
        // The webhook may have paid the order already: nothing read here
        // undoes that.
        if ($order->paid || !$order->isWajub()) {
            return $confirmation;
        }

        $current = $this->payments->current($order->id);
        if ($current === null) {
            return $confirmation;
        }

        $read = $this->settlement->outcome($current, true);
        if (Settlement::namesAnotherOrder($order, $read['transaction'])) {
            return $confirmation;
        }

        if ($read['outcome'] === Client::OUTCOME_SUCCEEDED) {
            // Short of the order's total, it counts toward it: the payer, who
            // was charged, reads on the confirmation page what is left.
            $this->settlement->apply($order, $current, $read, Settlement::SEEN_NOW);

            return $confirmation;
        }

        if ($read['outcome'] === Client::OUTCOME_FAILED) {
            $reason = Release::reasonOf($read['status']);
            $left = $this->release->leave($order, $reason, $read);

            return ['url' => $left['url'] !== '' ? $left['url'] : $confirmation['url'], 'message' => $left['message'], 'tone' => $left['tone']];
        }

        // Not final yet, or the read failed: neither says the payment failed.
        // The order stays as it is, and the confirmation page follows it.
        $this->settlement->remember($current, $read['transaction']);
        if (!Ledger::openWithMoney($read['transaction'])) {
            $this->orders->note($order, 'unconfirmed_' . md5($current->paymentId), $this->t->trans(
                'Wajub has not confirmed payment %payment% yet. The order will be updated by the webhook.',
                ['%payment%' => $current->paymentId],
                'Modules.Wajub.Admin'
            ));
        }

        return $confirmation;
    }
}
