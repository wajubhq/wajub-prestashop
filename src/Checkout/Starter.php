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
use Wajub\PrestaShop\Order\Instalments;
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\OpenPayments;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Order\ShopOrders;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\ApiReason;
use Wajub\PrestaShop\Support\CheckoutLocale;
use Wajub\PrestaShop\Support\CustomerMessage;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\MoneyFormatter;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The payment of an order placed at checkout (controllers/front/validation.php):
 * the payment the form of Mobile Money or Card holds, activated for the
 * order, or a payment on Wajub's hosted page (Other payment methods, or a
 * form that could not load). A port of the WordPress plugin's
 * FieldsGateway::process_payment() and hostedPayment().
 *
 * One payment open at a time per order: before a new one starts (another
 * method placed from a second tab, a page kept from before the order was
 * placed), each payment the order holds still open is settled first
 * (Order\OpenPayments): counted when it went through, cancelled while
 * nobody paid it, waited for while Wajub takes it. A paid order starts
 * nothing. The order and the form's payment are read again here, under the
 * order's lock the caller holds: the webhook may have counted the payment
 * while the caller waited for it.
 *
 * The rest of an order part of which is paid (rest()) is paid on Wajub's
 * hosted page too, for what is left of it, once the order's plan paid in
 * instalments, if it has one, is taken up again or closed
 * (Order\Instalments). A port of the WordPress plugin's
 * Gateway::process_payment() for an order paid again, and amountToPay().
 *
 * Each answer is `['ok' => bool, 'data' => …]`: for the form, what the
 * checkout's script needs to submit it; for the hosted page, where to send
 * the payer (`redirect`); else why the payer cannot pay (`code`, `message`,
 * and `redirect` where they go to read it), and whether the order must be
 * given back as a cart (`leave`: no payment can be taken for it this way,
 * nothing was charged).
 */
final class Starter
{
    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var OpenPayments */
    private $open;

    /** @var PreparedPayments */
    private $prepared;

    /** @var Settlement */
    private $settlement;

    /** @var Ledger */
    private $ledger;

    /** @var Offer */
    private $offer;

    /** @var Storefront */
    private $storefront;

    /** @var callable(): Client */
    private $client;

    /** @var Environment */
    private $environment;

    /** @var Translator */
    private $t;

    /** @var Logger */
    private $logger;

    /** @var Instalments */
    private $instalments;

    /** @var MoneyFormatter */
    private $money;

    /**
     * @param callable(): Client $client the client of the environment in use
     */
    public function __construct(ShopOrders $orders, PaymentStore $payments, OpenPayments $open, PreparedPayments $prepared, Settlement $settlement, Ledger $ledger, Offer $offer, Storefront $storefront, callable $client, Environment $environment, Translator $t, Logger $logger, Instalments $instalments, MoneyFormatter $money)
    {
        $this->instalments = $instalments;
        $this->money = $money;
        $this->orders = $orders;
        $this->payments = $payments;
        $this->open = $open;
        $this->prepared = $prepared;
        $this->settlement = $settlement;
        $this->ledger = $ledger;
        $this->offer = $offer;
        $this->storefront = $storefront;
        $this->client = $client;
        $this->environment = $environment;
        $this->t = $t;
        $this->logger = $logger;
    }

    /**
     * Before the order is placed: the cart's payment the form posted, ready
     * to be activated, or why it cannot pay the order. A form older than the
     * cart's payment (another tab, a renewal) leaves that payment for the
     * form that has it; any other refusal drops it, and the form prepares a
     * new one. No order is placed for a refused payment.
     *
     * @return array{slot: PaymentRecord}|array{ok: false, data: array<string, mixed>}
     */
    public function preflight(CartView $cart, string $kind, string $posted): array
    {
        $refreshed = self::error('refreshed', CheckoutStrings::refreshed($this->t));
        $slot = $this->prepared->current($cart->id, $kind);
        if ($slot === null || $posted === '') {
            return $refreshed;
        }
        if (!hash_equals($slot->paymentId, $posted)) {
            return $refreshed;
        }
        if (!$this->prepared->isCurrent($slot) || $slot->currency !== $cart->currency) {
            $this->prepared->forget($slot);

            return $refreshed;
        }

        // The payment keeps the expiry it was prepared with: too little left
        // to approve it on a phone. It lives again where Wajub can do that;
        // otherwise the form loads a new one.
        if ($this->prepared->lifeLeft($slot) < PreparedPayments::ACTIVATE_MARGIN) {
            try {
                $extended = $this->prepared->extend($slot, Amount::normalize($cart->total, $cart->currency), $cart->currency);
            } catch (MethodUnavailableException $e) {
                return $this->refused($kind, $e);
            } catch (AmountOutOfRangeException $e) {
                return $this->refused($kind, $e);
            }
            if ($extended === null) {
                $this->prepared->forget($slot);

                return $refreshed;
            }
            $slot = $extended;
        }

        return ['slot' => $slot];
    }

    /**
     * The payment of an order just placed with the form: the cart's payment,
     * activated for it. On success the checkout's script submits the form;
     * should it not, the same payment is on Wajub's hosted page (`hostedUrl`).
     *
     * The same payment placed again (a double click, a lost answer) sends
     * the same activation again. An order that holds another payment, or
     * part of whose total is paid, pays on Wajub's hosted page instead, once
     * that payment is settled: the form's payment asks the whole total, and
     * the order's payment of the moment is the last one started for it.
     *
     * @return array{ok: bool, data: array<string, mixed>}
     */
    public function fields(OrderView $order, CartView $cart, string $kind, PaymentRecord $slot, string $language): array
    {
        if (!$this->environment->isConfigured()) {
            return self::error('not_configured', CustomerMessage::shopUnavailable($this->t), true);
        }

        $order = $this->orders->find($order->id) ?? $order;
        $stopped = $this->stopped($order);
        if ($stopped !== null) {
            return $stopped;
        }
        $slot = $this->payments->find($slot->paymentId);
        if ($slot === null || $slot->idCart !== $order->idCart || $slot->mode !== PaymentRecord::MODE_FIELDS || $slot->method !== $kind
            || ($slot->idOrder !== 0 && $slot->idOrder !== $order->id)) {
            return self::error('refreshed', CheckoutStrings::refreshed($this->t));
        }

        $current = $this->payments->current($order->id);
        $again = $slot->idOrder === $order->id && $current !== null && $current->paymentId === $slot->paymentId;
        if (!$again) {
            $before = $this->beforeNewPayment($order);
            if ($before !== null) {
                return $before;
            }
            if ($slot->idOrder === $order->id || $this->payments->forOrder($order->id) !== []) {
                // The order moved on from this payment, or holds others: what
                // is left to pay, on Wajub's page, in the kind the payer chose.
                return $this->hosted($order, $cart, $kind, $language);
            }
        }

        $reference = str_starts_with($slot->reference, 'Order #') && $slot->idOrder === $order->id ? $slot->reference : $this->referenceFor($order);
        try {
            $slot = $this->prepared->activate($order, $slot, $this->activationParams($order, $cart, $kind, $reference));
        } catch (MethodUnavailableException $e) {
            return $this->refused($kind, $e);
        } catch (AmountOutOfRangeException $e) {
            return $this->refused($kind, $e);
        } catch (SlotGoneException $e) {
            $this->logger->info('Prepared payment gone at activation', ['order' => $order->id, 'payment' => $slot->paymentId, 'error' => $e->getMessage()]);
            // It went through meanwhile (the order placed again after the
            // payer approved it): it settles the order now.
            if ($e->payment !== null && Ledger::endedWithMoney((string) ($e->payment['status'] ?? ''))) {
                $this->settlement->apply($order, $slot, ['outcome' => Client::OUTCOME_SUCCEEDED, 'status' => (string) $e->payment['status'], 'transaction' => $e->payment], Settlement::SEEN_NOW);

                return ['ok' => false, 'data' => ['code' => 'paid', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
            }
            // Being paid in instalments: the confirmation page follows it.
            if ($e->payment !== null && Ledger::openWithMoney($e->payment)) {
                $this->settlement->remember($slot, $e->payment);

                return ['ok' => false, 'data' => ['code' => 'paid', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
            }

            return self::error('refreshed', CheckoutStrings::refreshed($this->t), true);
        } catch (\Throwable $e) {
            // Kept: the same activation can be sent again, and Wajub answers
            // a repeated one as done.
            return self::error('start_failed', CustomerMessage::forPaymentStart($e, $this->t, $this->logger));
        }

        return ['ok' => true, 'data' => [
            'fields' => '1',
            'kind' => $kind,
            'orderId' => $order->id,
            'paymentId' => $slot->paymentId,
            'sessionId' => $slot->sessionToken(),
            'statusUrl' => $this->storefront->status($order),
            'returnUrl' => $this->storefront->confirmation($order),
            // The same payment on Wajub's own page: where the payer finishes
            // it when the form cannot (a card provider's window was blocked).
            'hostedUrl' => CheckoutLocale::hostedPageUrl($slot->authorizationUrl(), $language),
            'releaseUrl' => $this->storefront->release($order),
            'orderToken' => $this->storefront->orderToken($order),
        ]];
    }

    /**
     * A payment on Wajub's hosted page for an order just placed: restricted
     * to the kind of the option (Mobile Money, cards), or for "Other payment
     * methods" to what the account offers besides the options of the form.
     * For what is left to pay of the order: its total, or the rest of it.
     * `$reads`: what Wajub said of some of the order's payments a moment ago
     * in this request, by payment id, not read again.
     *
     * @param array<string, array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true, missing?: true}> $reads
     *
     * @return array{ok: bool, data: array<string, mixed>}
     */
    public function hosted(OrderView $order, CartView $cart, string $kind, string $language, array $reads = []): array
    {
        if (!$this->environment->isConfigured()) {
            return self::error('not_configured', CustomerMessage::shopUnavailable($this->t), true);
        }

        $order = $this->orders->find($order->id) ?? $order;
        $stopped = $this->stopped($order);
        if ($stopped !== null) {
            return $stopped;
        }

        $environment = $this->environment->isLive() ? 'live' : 'sandbox';
        // A second click, "Place order" again after a lost answer, "Pay the
        // rest" again: the payment the order holds on Wajub's page, nobody
        // having paid it yet, as Wajub says now, for what is left to pay,
        // rather than a second one the payer could pay as well.
        $current = $this->payments->current($order->id);
        if ($current !== null && $current->mode === PaymentRecord::MODE_HOSTED && $current->method === $kind && $current->state === PaymentRecord::ACTIVE
            && $current->environment === $environment && $current->authorizationUrl() !== ''
            && abs($current->amount - $this->ledger->due($order, $environment)) < 0.01) {
            $read = $reads[$current->paymentId] ?? $this->settlement->outcome($current, true);
            $reads[$current->paymentId] = $read;
            if ($read['outcome'] === Client::OUTCOME_PENDING && empty($read['unreachable']) && empty($read['missing'])
                && strtolower($read['status']) === 'pending' && !Settlement::namesAnotherOrder($order, $read['transaction'])) {
                return ['ok' => true, 'data' => ['redirect' => CheckoutLocale::hostedPageUrl($current->authorizationUrl(), $language)]];
            }
        }

        // What the account offers is asked for what this payment would ask
        // (the order's total, or what is left of it), before any other
        // payment of the order is touched: nothing is cancelled for a
        // payment that cannot start.
        $accept = [];
        if (Methods::isFields($kind)) {
            $accept = ['types' => Methods::ACCEPT_TYPES[$kind]];
        } else {
            $left = $this->offer->typesLeft($order->currency, $this->ledger->due($order, $environment), $cart->country);
            if ($left === []) {
                // Given back as a cart when it was just placed this way; an
                // order that holds a payment already keeps it.
                return self::error('method_unavailable', CheckoutStrings::fields($kind, $this->t)['unavailable'], $this->payments->forOrder($order->id) === []);
            }
            if ($left !== null) {
                $accept = ['types' => $left];
            }
        }

        $before = $this->beforeNewPayment($order, $language, $reads);
        if ($before !== null) {
            return $before;
        }
        $order = $this->orders->find($order->id) ?? $order;

        [$amount, $description, $held] = $this->amountToPay($order, $environment);
        if ($amount <= 0) {
            // Its payments cover it already (its total was lowered since).
            $this->settlement->completeIfCovered($order, $environment);

            return ['ok' => false, 'data' => ['code' => 'paid', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
        }

        $reference = $this->referenceFor($order);
        try {
            $created = Client::parsePaymentResponse(($this->client)()->createPayment([
                'amount' => $amount,
                'currency' => $order->currency,
                'description' => $description,
                'reference' => $reference,
                'callback' => PreparedPayments::callback($this->storefront->returnUrl($order), $environment === 'live'),
                'customer' => ['email' => $cart->email, 'phone' => $cart->phone, 'name' => $cart->name],
                'accept' => $accept,
                'metadata' => $this->metadata($order, $kind, PaymentRecord::MODE_HOSTED),
            ]));
        } catch (\Throwable $e) {
            // The payer reads what to do next; the merchant reads what Wajub
            // answered, and what is left to pay of an order paid in part.
            $this->orders->note($order, 'start_failed_' . md5($reference . '|' . $e->getMessage()), $this->startFailureNote($e, $order, $amount, $held));

            return self::error('start_failed', CustomerMessage::forPaymentStart($e, $this->t, $this->logger), $held <= 0);
        }
        if ($created['id'] === '') {
            return self::error('start_failed', CheckoutStrings::fields($kind, $this->t)['startFailed'], $held <= 0);
        }

        $record = new PaymentRecord();
        $record->paymentId = $created['id'];
        $record->reference = $reference;
        $record->idShop = $order->idShop;
        $record->idCart = $order->idCart;
        $record->idOrder = $order->id;
        $record->idCustomer = $order->idCustomer;
        $record->environment = $environment;
        $record->method = $kind;
        $record->mode = PaymentRecord::MODE_HOSTED;
        $record->state = PaymentRecord::ACTIVE;
        $record->status = $created['status'];
        $record->currency = $order->currency;
        $record->amount = $amount;
        $record->setMeta('authorization_url', $created['authorization_url']);
        $this->payments->save($record);
        $this->logger->info('Payment started on the hosted page', ['order' => $order->id, 'payment' => $record->paymentId, 'kind' => $kind]);
        if ($held > 0) {
            $this->orders->note($order, 'rest_' . md5($record->paymentId), $this->t->trans(
                'Wajub payment %payment% was started for the %amount% left to pay (%held% already received).',
                ['%payment%' => $record->paymentId, '%amount%' => $this->money->format($amount, $order->currency), '%held%' => $this->money->format($held, $order->currency)],
                'Modules.Wajub.Admin'
            ));
        }

        return ['ok' => true, 'data' => ['redirect' => CheckoutLocale::hostedPageUrl($created['authorization_url'], $language)]];
    }

    /**
     * The payer pays the rest of an order part of which is paid, or pays
     * again an order whose payment did not go through (from its
     * confirmation page, or their account's order page): a payment on
     * Wajub's hosted page, of the kind the order was placed with, for what
     * is left to pay. First the order's plan paid in instalments, if it has
     * one: taken up again on its own page, waited for while an instalment
     * is with the payer, or closed keeping what it collected
     * (Order\Instalments); then every other payment still open
     * (beforeNewPayment()). Never for a paid or cancelled order. The caller
     * holds the order's lock.
     *
     * @return array{ok: bool, data: array<string, mixed>}
     */
    public function rest(OrderView $order, CartView $cart, string $language): array
    {
        if (!$this->environment->isConfigured()) {
            return self::error('not_configured', CustomerMessage::shopUnavailable($this->t));
        }
        $order = $this->orders->find($order->id) ?? $order;
        if ($order->paid) {
            return ['ok' => false, 'data' => ['code' => 'paid', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
        }
        if (!$order->isWajub() || $order->paidOnce || $order->stateId === $this->orders->canceledState()) {
            return self::error('order_cancelled', $this->t->trans('This order can no longer be paid online. Contact the shop to complete it.', [], 'Modules.Wajub.Shop'));
        }

        $environment = $this->environment->isLive() ? 'live' : 'sandbox';
        // Its payments cover it already: the merchant lowered its total.
        if ($this->settlement->completeIfCovered($order, $environment)) {
            return ['ok' => false, 'data' => ['code' => 'paid', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
        }
        $reads = [];
        $plan = $this->plan($order, $environment, $language, $reads);
        if ($plan !== null) {
            return $plan;
        }

        $records = $this->payments->forOrder($order->id);
        $kind = $records !== [] && in_array($records[0]->method, Methods::KINDS, true) ? $records[0]->method : Methods::OTHER;

        return $this->hosted($order, $cart, $kind, $language, $reads);
    }

    /**
     * What a new payment of the order asks, and how Wajub's page names it:
     * the order's total, or, once payments short of it count toward it in
     * `$environment`, what is left, which the payer reads on Wajub's page
     * ("4 000 FCFA already received; 6 000 FCFA left to pay"). A port of the
     * plugin's Gateway::amountToPay().
     *
     * @return array{0: float, 1: string, 2: float} amount, description, what the order holds already
     */
    public function amountToPay(OrderView $order, string $environment): array
    {
        $held = $this->ledger->held($order, $environment);
        if ($held <= 0) {
            return [Ledger::total($order), $this->t->trans('Order #%reference%', ['%reference%' => $order->reference], 'Modules.Wajub.Shop'), 0.0];
        }
        $due = $this->ledger->due($order, $environment);

        return [$due, $this->t->trans('Order #%reference%: %held% already received; %due% left to pay', [
            '%reference%' => $order->reference,
            '%held%' => $this->money->format($held, $order->currency),
            '%due%' => $this->money->format($due, $order->currency),
        ], 'Modules.Wajub.Shop'), $held];
    }

    /**
     * The merchant's note of a payment that could not start: Wajub's own
     * words and code, with any key or signing secret they quote masked. The
     * payment of what was left of an order paid in part says what is left.
     */
    public function startFailureNote(\Throwable $error, OrderView $order, float $amount, float $held): string
    {
        if ($held > 0) {
            return $this->t->trans('Wajub could not start the payment of the %amount% left to pay (%held% already received): %reason%', [
                '%amount%' => $this->money->format($amount, $order->currency),
                '%held%' => $this->money->format($held, $order->currency),
                '%reason%' => ApiReason::of($error, $this->t, 300),
            ], 'Modules.Wajub.Admin');
        }

        return $this->t->trans('Wajub could not start the payment: %reason%', ['%reason%' => ApiReason::of($error, $this->t, 300)], 'Modules.Wajub.Admin');
    }

    /**
     * What the order's plan paid in instalments becomes before the rest is
     * asked for (Order\Instalments): null to go on, otherwise where the
     * payer goes.
     *
     * @param array<string, array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true, missing?: true}> $reads what Wajub said of the payment, given back for the next checks
     *
     * @return array{ok: bool, data: array<string, mixed>}|null
     */
    private function plan(OrderView $order, string $environment, string $language, array &$reads = []): ?array
    {
        $plan = $this->instalments->settle($order, $environment);
        $reads = ($plan['read'] ?? []) + $reads;
        switch ($plan['to']) {
            case Instalments::RESUME:
                return ['ok' => true, 'data' => ['redirect' => CheckoutLocale::hostedPageUrl($plan['url'] ?? '', $language)]];
            case Instalments::WAIT:
                return ['ok' => false, 'data' => ['code' => 'pending', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
            case Instalments::PAID:
                return ['ok' => false, 'data' => ['code' => 'paid', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
            case Instalments::STOP:
                return self::error('busy', $this->t->trans('Your payment could not be started. Please try again in a moment.', [], 'Modules.Wajub.Shop'));
        }

        return null;
    }

    /**
     * What an activation sends, built the same way for the same order every
     * time: Wajub compares it with the activation it already took. The
     * reference and the description name the order, so the Wajub Dashboard
     * and the payer see which order it pays.
     *
     * @return array<string, mixed>
     */
    public function activationParams(OrderView $order, CartView $cart, string $kind, string $reference): array
    {
        $environment = $this->environment->isLive() ? 'live' : 'sandbox';

        return [
            'amount' => Ledger::total($order),
            'currency' => $order->currency,
            'customer' => ['email' => $cart->email, 'phone' => $cart->phone, 'name' => $cart->name],
            'callback' => PreparedPayments::callback($this->storefront->returnUrl($order), $environment === 'live'),
            'metadata' => $this->metadata($order, $kind, PaymentRecord::MODE_FIELDS),
            'reference' => $reference,
            'description' => $this->t->trans('Order #%reference%', ['%reference%' => $order->reference], 'Modules.Wajub.Shop'),
        ];
    }

    /**
     * The reference of the order's next payment, as the Wajub Dashboard
     * lists it: "Order #XKBKNABJK" for its first, "Order #XKBKNABJK-2" for
     * the next (the rest of a payment short of the total).
     */
    public function referenceFor(OrderView $order): string
    {
        $count = count($this->payments->forOrder($order->id));

        return 'Order #' . $order->reference . ($count > 0 ? '-' . ($count + 1) : '');
    }

    /**
     * @return array<string, string>
     */
    private function metadata(OrderView $order, string $kind, string $mode): array
    {
        return [
            'source' => 'prestashop',
            'source_id' => (string) $order->id,
            'order_id' => (string) $order->id,
            'order_reference' => $order->reference,
            'cart_id' => (string) $order->idCart,
            'shop_id' => (string) $order->idShop,
            'method' => $kind,
            'mode' => $mode,
        ];
    }

    /**
     * Why the order takes no new payment: paid already (by the webhook, or
     * another tab, while the caller waited for the lock), or given back as
     * a cart. Null when it may.
     *
     * @return array{ok: false, data: array<string, mixed>}|null
     */
    private function stopped(OrderView $order): ?array
    {
        if ($order->paid) {
            return ['ok' => false, 'data' => ['code' => 'paid', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
        }
        if ($order->stateId === $this->orders->canceledState()) {
            // Given back as a cart already (another tab): the payer places
            // the cart they have now.
            return ['ok' => false, 'data' => ['code' => 'refreshed', 'message' => CheckoutStrings::refreshed($this->t), 'redirect' => $this->storefront->checkout()]];
        }

        return null;
    }

    /**
     * What the order's payments still open become before a new one starts
     * (Order\OpenPayments). Null: start it. Otherwise where the payer goes:
     * the order's confirmation page once it is paid, or while one of them
     * may still be charged (it follows it); or nowhere, when Wajub could not
     * say, and the payer tries again in a moment.
     *
     * @param array<string, array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true, missing?: true}> $reads
     *
     * @return array{ok: bool, data: array<string, mixed>}|null
     */
    private function beforeNewPayment(OrderView $order, string $language = '', array $reads = []): ?array
    {
        $settled = $this->open->settle($order, Settlement::SEEN_BEFORE_NEW, $reads);
        foreach ($settled['cancelled'] as $paymentId) {
            $this->orders->note($order, 'replaced_' . md5($paymentId), $this->t->trans(
                'The customer started a new payment for this order: the earlier Wajub payment %payment%, not completed, was cancelled.',
                ['%payment%' => $paymentId],
                'Modules.Wajub.Admin'
            ));
        }
        switch ($settled['verdict']) {
            case OpenPayments::PAID:
                return ['ok' => false, 'data' => ['code' => 'paid', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
            case OpenPayments::WAIT:
                // Found paid in instalments as it was cancelled (Wajub
                // refused: it collected an instalment meanwhile): taken up
                // again, or closed, as the order's plan.
                if ($language !== '' && $this->ledger->instalmentsOpen($order) !== null) {
                    $plan = $this->plan($order, $this->environment->isLive() ? 'live' : 'sandbox', $language);
                    if ($plan !== null) {
                        return $plan;
                    }

                    return $this->beforeNewPayment($order);
                }

                return ['ok' => false, 'data' => ['code' => 'pending', 'message' => '', 'redirect' => $this->storefront->confirmation($order)]];
            case OpenPayments::UNCHECKED:
                return self::error('busy', $this->t->trans('We could not check your payment with Wajub. Please try again in a moment.', [], 'Modules.Wajub.Shop'));
        }

        return null;
    }

    /**
     * Wajub refused this method for the cart or the order: out of the
     * currency's limits, or with no way left to pay it in this kind.
     *
     * @return array{ok: false, data: array<string, mixed>}
     */
    private function refused(string $kind, \RuntimeException $error): array
    {
        $words = CheckoutStrings::fields($kind, $this->t);

        return $error instanceof AmountOutOfRangeException
            ? self::error('amount_out_of_range', $words['amountOutOfRange'], true)
            : self::error('method_unavailable', $words['unavailable'], true);
    }

    /**
     * @return array{ok: false, data: array<string, mixed>}
     */
    private static function error(string $code, string $message, bool $leave = false): array
    {
        return ['ok' => false, 'data' => ['code' => $code, 'message' => $message, 'leave' => $leave]];
    }
}
