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

use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Api\InvalidRequestException;
use Wajub\PrestaShop\Api\NotFoundException;
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The payment the form of Mobile Money or Card runs on: prepared for the
 * payer's cart before the order exists (`deferred: true`), so the form
 * loads while they fill in the checkout, and activated for the order once
 * "Place order" has created it. Nobody can pay it before then. A port of the
 * WordPress plugin's Fields\PreparedPayments.
 *
 * One per cart and kind (the "slot"), a `prepared` row of `wajub_payment`,
 * never handed to the browser whole (its id and session only). It is reused
 * across the checkout's page views, brought to the cart's total when that
 * changes (a coupon, the carrier), and dropped when the shop's environment
 * or key changed, the currency changed, or too little of its life is left
 * and Wajub cannot extend it. A dropped payment is never cancelled: never
 * activated, it sends no webhook, and Wajub prunes it.
 *
 * Its reference names no order (`psf_…`): its activation gives it the
 * order's.
 */
final class PreparedPayments
{
    /**
     * Minutes a prepared payment lives. Activation keeps the expiry it was
     * prepared with, so this is also how long the payer has to pay.
     */
    public const EXPIRES_IN = 120;

    /** Seconds of life a slot must have left to be handed to the form again. */
    public const REUSE_MARGIN = 1800;

    /** Seconds of life a slot must have left to be activated for an order. */
    public const ACTIVATE_MARGIN = 600;

    /** Seconds the slot's expiry is counted short of the API's, against clock drift. */
    private const EXPIRY_SLACK = 60;

    /** Hosts the API accepts an http return to, in Sandbox only (PaymentCallbackUrl). */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /** @var PaymentStore */
    private $payments;

    /** @var callable(): Client */
    private $client;

    /** @var Environment */
    private $environment;

    /** @var string */
    private $shopName;

    /** @var Translator */
    private $t;

    /** @var Logger */
    private $logger;

    /**
     * @param callable(): Client $client the client of the environment in use
     */
    public function __construct(PaymentStore $payments, callable $client, Environment $environment, string $shopName, Translator $t, Logger $logger)
    {
        $this->payments = $payments;
        $this->client = $client;
        $this->environment = $environment;
        $this->shopName = $shopName;
        $this->t = $t;
        $this->logger = $logger;
    }

    /**
     * The payment the form of `$kind` can load for this cart: the cart's,
     * brought to its total, or a new one (`$renew`: always a new one, after
     * the form's session ended).
     *
     * @throws MethodUnavailableException when no payment method of this kind is left for this currency and amount
     * @throws AmountOutOfRangeException when the amount is outside the currency's limits
     * @throws \Throwable when Wajub cannot be reached, or the cart has nothing to pay
     */
    public function forCart(CartView $cart, string $kind, bool $renew = false): PaymentRecord
    {
        if (!$cart->payable() || !Methods::isFields($kind)) {
            throw new \InvalidArgumentException('The cart has nothing to pay');
        }
        $currency = $cart->currency;
        $amount = Amount::normalize($cart->total, $currency);

        $slot = $this->payments->preparedFor($cart->id, $kind);
        if ($slot !== null && ($renew || !$this->reusable($slot, $currency))) {
            // A prepare that fails then leaves nothing stale behind: the
            // order would be refused against a slot the form never got.
            $this->forget($slot);
            $slot = null;
        }
        if ($slot === null) {
            return $this->prepare($cart, $kind, $amount, $currency);
        }

        if ($this->lifeLeft($slot) < self::REUSE_MARGIN) {
            // Too little left to fill in the checkout and pay: the same
            // payment living again, so the form keeps its session, or a new
            // one where Wajub cannot do that.
            $extended = $this->extend($slot, $amount, $currency);
            if ($extended !== null) {
                return $extended;
            }
            $this->forget($slot);

            return $this->prepare($cart, $kind, $amount, $currency);
        }

        if (self::sameAmount($slot->amount, $amount)) {
            return $slot;
        }

        // The cart changed (a coupon, the carrier, the country): the same
        // payment, so the form the payer is filling keeps its session.
        try {
            ($this->client)()->updatePreparedPayment($slot->paymentId, $amount, $currency);
            $slot->amount = $amount;
            $this->payments->save($slot);

            return $slot;
        } catch (InvalidRequestException $e) {
            // The new total is out of the currency's limits, or of what this
            // method's kind can pay, as at preparation.
            $this->refusedChange($slot, $e);
            throw $e;
        } catch (NotFoundException $e) {
            // Pruned: Wajub no longer has it.
        } catch (ApiException $e) {
            // 409: activated, cancelled or expired meanwhile.
            if ($e->httpStatus !== 409) {
                throw $e;
            }
        }

        $this->forget($slot);

        return $this->prepare($cart, $kind, $amount, $currency);
    }

    /** The cart's slot for a kind, not activated yet, or null. */
    public function current(int $idCart, string $kind): ?PaymentRecord
    {
        return $this->payments->preparedFor($idCart, $kind);
    }

    public function forget(PaymentRecord $slot): void
    {
        if ($slot->state === PaymentRecord::PREPARED && $slot->idOrder === 0) {
            $slot->state = PaymentRecord::DROPPED;
            $this->payments->save($slot);
        }
    }

    /**
     * Whether the slot was prepared with the key the shop uses now: a switch
     * to Live, or to another account's key, leaves it unknown to the API the
     * shop now calls.
     */
    public function isCurrent(PaymentRecord $slot): bool
    {
        return $slot->environment === $this->environmentSlug()
            && $slot->meta('key') !== ''
            && hash_equals(Keys::fingerprint($this->environment->activeKey()), $slot->meta('key'));
    }

    /** Seconds the slot has left to live. */
    public function lifeLeft(PaymentRecord $slot): int
    {
        return $slot->expiresAt - time();
    }

    /**
     * The slot's payment living EXPIRES_IN minutes again, from now, at
     * `$amount`, while it is not activated. Null when Wajub kept its expiry,
     * no longer has the payment, or did not answer: the caller then does
     * without it.
     *
     * @throws MethodUnavailableException when no payment method of this kind is left for this currency and amount
     * @throws AmountOutOfRangeException when the amount is outside the currency's limits
     */
    public function extend(PaymentRecord $slot, float $amount, string $currency): ?PaymentRecord
    {
        if ($slot->state !== PaymentRecord::PREPARED || $slot->idOrder !== 0 || $this->lifeLeft($slot) <= 0) {
            return null;
        }

        $sentAt = time();
        try {
            $payment = ($this->client)()->updatePreparedPayment($slot->paymentId, $amount, $currency, self::EXPIRES_IN);
        } catch (InvalidRequestException $e) {
            $this->refusedChange($slot, $e);

            return null;
        } catch (\Throwable $e) {
            return null;
        }

        // Moved past the slot's, and far enough to serve the payer again: an
        // older API answers the expiry the payment already had.
        $expiresAt = is_string($payment['expires_at'] ?? null) ? strtotime($payment['expires_at']) : false;
        if ($expiresAt === false
            || $expiresAt - self::EXPIRY_SLACK <= $slot->expiresAt
            || $expiresAt - self::EXPIRY_SLACK - time() < self::REUSE_MARGIN) {
            return null;
        }

        $slot->amount = $amount;
        $slot->expiresAt = min($expiresAt, $sentAt + self::EXPIRES_IN * 60) - self::EXPIRY_SLACK;
        $this->payments->save($slot);

        return $slot;
    }

    /**
     * Makes the slot's payment the order's: the amount brought to the
     * order's total while it can still change, the slot bound to the order,
     * then the activation. Once this returns, the form can take the payment.
     *
     * The slot is bound to the order before the activation is sent: the
     * webhook of a payment activated a moment later finds its order either
     * way. The activation body is the same for the same order every time
     * (Api\Client::activationBody(), its hash kept): a double submit, or the
     * same order placed again after a lost answer, sends the very same body,
     * which Wajub answers as done rather than refusing another one; one sent
     * before is read once answered, as is any refusal (409), since only a
     * read says whose the payment is and how it stands.
     *
     * @param array<string, mixed> $params the activation (amount, currency, customer, callback, metadata, reference, description)
     *
     * @throws SlotGoneException when the payment can no longer pay this order (with the payment read, when it was)
     * @throws MethodUnavailableException when, at the order's total, no payment method of this kind is left
     * @throws AmountOutOfRangeException when the order's total is outside the currency's limits
     * @throws \Throwable when Wajub refused or did not answer, or the payment could not be read: the same activation can be sent again
     */
    public function activate(OrderView $order, PaymentRecord $slot, array $params): PaymentRecord
    {
        $client = ($this->client)();
        $amount = Amount::normalize((float) ($params['amount'] ?? 0), $order->currency);

        if ($slot->idOrder !== $order->id && !self::sameAmount($slot->amount, $amount)) {
            $this->update($client, $slot, $amount, $order->currency);
        }

        $body = Client::activationBody($params);
        $hash = hash('sha256', (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // Sent before for this order, with this body: Wajub answers it as
        // done, whatever became of the payment since. A read says.
        $confirm = $slot->idOrder === $order->id && $slot->activationHash === $hash;

        $slot->idOrder = $order->id;
        $slot->idCustomer = $order->idCustomer;
        $slot->reference = (string) ($body['reference'] ?? $slot->reference);
        $slot->activationHash = $hash;
        $slot->state = PaymentRecord::ACTIVE;
        $this->payments->save($slot);

        $refusal = null;
        try {
            try {
                $this->send($client, $slot, $params);
            } catch (InvalidRequestException $e) {
                // No method of this kind left for the order: said so.
                if (self::refuses($e, 'accept')) {
                    throw new MethodUnavailableException($e->getMessage(), 0, $e);
                }
                // The order's total and the payment's differ: brought level, once.
                if (!self::refuses($e, 'amount')) {
                    throw $e;
                }
                $this->update($client, $slot, $amount, $order->currency);
                $this->send($client, $slot, $params);
            }
        } catch (ApiException $e) {
            // After any other error the activation may have gone through, and
            // the same one is sent again.
            if ($e->httpStatus !== 409) {
                throw $e;
            }
            $refusal = $e;
        }

        $payment = null;
        if ($refusal !== null || $confirm) {
            $payment = Client::extractTransaction($client->retrievePayment($slot->paymentId));
        }

        if ($payment !== null && !self::activatedFor($payment, $order)) {
            throw $this->gone((string) ($payment['status'] ?? ''), null, $refusal);
        }
        if ($payment !== null && !self::openFor($payment, $order, $amount)) {
            // This order's, but ended, paid in instalments, or for another
            // total: it stays the order's, for the order to settle with it.
            throw $this->gone((string) ($payment['status'] ?? ''), $payment, $refusal);
        }

        return $slot;
    }

    /**
     * The address Wajub sends the payer back to, or '' where the API would
     * refuse it and with it the whole payment: Wajub returns payers to HTTPS
     * addresses only, and in Sandbox to http://localhost and http://127.0.0.1
     * too (PaymentCallbackUrl). A shop served over plain HTTP elsewhere gets
     * no return: its payer stays on Wajub's confirmation.
     */
    public static function callback(string $url, bool $live): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }

        return $scheme === 'https' || (!$live && $scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true)) ? $url : '';
    }

    /** The exception a 422 on `accept` or on `amount` means, or null for another field. */
    public static function refusalOf(InvalidRequestException $error): ?\RuntimeException
    {
        if (self::refuses($error, 'accept')) {
            return new MethodUnavailableException($error->getMessage(), 0, $error);
        }
        if (self::refuses($error, 'amount')) {
            return new AmountOutOfRangeException($error->getMessage(), 0, $error);
        }

        return null;
    }

    /**
     * Whether Wajub activated the payment for this very order.
     *
     * @param array<string, mixed> $payment
     */
    public static function activatedFor(array $payment, OrderView $order): bool
    {
        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        $named = $metadata['order_id'] ?? null;

        return (is_string($named) || is_int($named)) && (string) $named === (string) $order->id;
    }

    /** Equal as the API compares them: to the cent. */
    public static function sameAmount(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);
    }

    /**
     * Whether the form can still pay this order with the payment: activated
     * for it, for its total, and still open, nothing collected yet.
     *
     * @param array<string, mixed> $payment
     */
    private static function openFor(array $payment, OrderView $order, float $amount): bool
    {
        $status = strtolower((string) ($payment['status'] ?? ''));

        return in_array($status, ['pending', 'processing'], true)
            && !Ledger::openWithMoney($payment)
            && self::activatedFor($payment, $order)
            && strtoupper((string) ($payment['currency'] ?? '')) === $order->currency
            && is_numeric($payment['amount'] ?? null)
            && self::sameAmount((float) $payment['amount'], $amount);
    }

    /**
     * A new payment for the form of `$kind`, restricted to its kind.
     */
    private function prepare(CartView $cart, string $kind, float $amount, string $currency): PaymentRecord
    {
        $reference = self::newReference();
        try {
            $prepared = ($this->client)()->preparePayment([
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
                // The order does not exist yet: the shop names the payment
                // until its activation names the order.
                'description' => $this->t->trans('Order at %shop%', ['%shop%' => $this->shopName], 'Modules.Wajub.Shop'),
                'expires_in' => self::EXPIRES_IN,
                'accept' => ['types' => Methods::ACCEPT_TYPES[$kind]],
                'metadata' => [
                    'source' => 'prestashop',
                    'mode' => PaymentRecord::MODE_FIELDS,
                    'method' => $kind,
                    'cart_id' => (string) $cart->id,
                    'shop_id' => (string) $cart->idShop,
                ],
            ]);
        } catch (InvalidRequestException $e) {
            $refusal = self::refusalOf($e);
            throw $refusal ?? $e;
        }

        if ($prepared['id'] === '' || $prepared['session_id'] === '') {
            throw new \RuntimeException('Wajub prepared a payment without an id or a session');
        }

        $slot = new PaymentRecord();
        $slot->paymentId = $prepared['id'];
        $slot->reference = $reference;
        $slot->idShop = $cart->idShop;
        $slot->idCart = $cart->id;
        $slot->idCustomer = $cart->idCustomer;
        $slot->environment = $this->environmentSlug();
        $slot->method = $kind;
        $slot->mode = PaymentRecord::MODE_FIELDS;
        $slot->state = PaymentRecord::PREPARED;
        $slot->status = $prepared['status'] !== '' ? $prepared['status'] : 'pending';
        $slot->currency = $currency;
        $slot->amount = $amount;
        $slot->expiresAt = (int) $prepared['expires_at'] - self::EXPIRY_SLACK;
        $slot->setMeta('token', $prepared['session_id']);
        $slot->setMeta('authorization_url', $prepared['authorization_url']);
        $slot->setMeta('key', Keys::fingerprint($this->environment->activeKey()));
        $this->payments->save($slot);
        $this->logger->info('Payment prepared for a cart', ['cart' => $cart->id, 'kind' => $kind, 'payment' => $slot->paymentId]);

        return $slot;
    }

    /**
     * Whether the slot can serve this cart, its life aside (forCart()
     * extends a slot with too little left).
     */
    private function reusable(PaymentRecord $slot, string $currency): bool
    {
        return $this->isCurrent($slot) && $slot->currency === $currency && $slot->idOrder === 0 && $slot->sessionToken() !== '';
    }

    /**
     * The slot's payment brought to a new amount, while it can still change.
     *
     * @throws SlotGoneException when it can no longer change (404 or 409)
     */
    private function update(Client $client, PaymentRecord $slot, float $amount, string $currency): void
    {
        try {
            $client->updatePreparedPayment($slot->paymentId, $amount, $currency);
        } catch (InvalidRequestException $e) {
            $this->refusedChange($slot, $e);
            throw $e;
        } catch (NotFoundException $e) {
            throw $this->gone('', null, $e);
        } catch (ApiException $e) {
            if ($e->httpStatus === 409) {
                throw $this->gone('', null, $e);
            }
            throw $e;
        }

        $slot->amount = $amount;
        $this->payments->save($slot);
    }

    /**
     * One activation. A refusal (409) is the caller's to read.
     *
     * @param array<string, mixed> $params
     *
     * @throws SlotGoneException when Wajub no longer has the payment (404)
     * @throws ApiException when Wajub refused it (409, or a field: 422) or did not answer
     */
    private function send(Client $client, PaymentRecord $slot, array $params): void
    {
        try {
            $client->activatePayment($slot->paymentId, $params);
        } catch (NotFoundException $e) {
            throw $this->gone('', null, $e);
        }
    }

    /**
     * A new amount Wajub refused as it refuses one at preparation: the slot
     * is dropped, so the form says why and asks anew. Returns for a refusal
     * of anything else.
     *
     * @throws MethodUnavailableException|AmountOutOfRangeException
     */
    private function refusedChange(PaymentRecord $slot, InvalidRequestException $error): void
    {
        $refusal = self::refusalOf($error);
        if ($refusal === null) {
            return;
        }
        $this->forget($slot);
        throw $refusal;
    }

    /**
     * @param array<string, mixed>|null $payment
     */
    private function gone(string $status, ?array $payment, ?\Throwable $previous): SlotGoneException
    {
        $answer = $previous instanceof ApiException
            ? sprintf('%s (HTTP %d)', $previous->getMessage(), $previous->httpStatus)
            : ($status !== '' ? 'Wajub status: ' . $status : 'gone');

        return new SlotGoneException('The payment prepared at checkout can no longer pay this order: ' . $answer, $payment, $previous);
    }

    /** Whether a 422 names this field (`amount`, or `accept` and its lists). */
    private static function refuses(InvalidRequestException $error, string $field): bool
    {
        $errors = is_array($error->body['errors'] ?? null) ? array_keys($error->body['errors']) : [];
        foreach ($errors as $key) {
            $key = strtolower((string) $key);
            if ($key === $field || str_starts_with($key, $field . '.')) {
                return true;
            }
        }

        return false;
    }

    /** A reference for a payment prepared before its order exists: `psf_<16 hex>_<unix time>`. */
    private static function newReference(): string
    {
        return 'psf_' . bin2hex(random_bytes(8)) . '_' . time();
    }

    private function environmentSlug(): string
    {
        return $this->environment->isLive() ? 'live' : 'sandbox';
    }
}
