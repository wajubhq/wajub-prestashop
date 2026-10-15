<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Api;

use Wajub\PrestaShop\Http\Transport;
use Wajub\PrestaShop\Http\TransportException;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Wajub API, as the module calls it: payments (hosted, and prepared for
 * the payment form in the checkout), what an account offers, refunds, the
 * webhook endpoints, and the key's own revocation. A port of the WordPress
 * plugin's API\Client, on ext-curl (Http\CurlTransport), with only the calls
 * the module makes.
 *
 * A client holds one key, for one environment, and never another: the
 * caller hands it the key of the environment in use (Settings\Environment).
 * Sandbox without its key has no client that can pay, and no request is
 * ever sent with the live key in its place.
 */
final class Client
{
    public const BASE_URL = 'https://api.wajub.com';

    private const TIMEOUT = 30;

    /** For calls an admin waits on: checking a key, registering a webhook, revoking a key. */
    private const CHECK_TIMEOUT = 10;

    /**
     * For a read a page can do without (the payment methods an account
     * offers, which the checkout reads): asked once, briefly, so a Wajub out
     * of reach holds the checkout three seconds at most.
     */
    private const OPTIONAL_TIMEOUT = 3;

    /**
     * For the calls a payer waits on at checkout, before the payment form
     * can take the payment: preparing, updating and activating a payment.
     */
    private const CHECKOUT_TIMEOUT = 15;

    /**
     * Minutes a prepared payment lives when the caller does not say: the
     * length of a checkout, not of a payment link (the API's own default is a
     * day). An activated payment keeps the expiry it was prepared with.
     */
    private const PREPARED_EXPIRES_IN = 120;

    /** The lifetime POST /payments accepts for `expires.in`, in minutes. */
    private const EXPIRES_IN_MIN = 5;

    private const EXPIRES_IN_MAX = 43200;

    /**
     * The lists an `accept` restriction takes (App\Support\PaymentAcceptance):
     * [at most this many entries, each this long at most, the pattern of one].
     * A type may hold a dot, as the API writes some (`bank.redirect`).
     */
    private const ACCEPT_LISTS = [
        'countries' => [60, 2, '/^[A-Za-z]{2}$/'],
        'channels' => [60, 64, '/^[A-Za-z0-9._-]+$/'],
        'types' => [20, 32, '/^[A-Za-z0-9._-]+$/'],
    ];

    private const MAX_ATTEMPTS = 2;

    private const IDEMPOTENCY_PREFIX = 'wajub-prestashop';

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_PENDING = 'pending';

    /** A return whose `reference` is not the order's payment. */
    public const OUTCOME_MISMATCH = 'mismatch';

    /** The reasons POST /refunds accepts. Any other value, or none, is a 422. */
    private const REFUND_REASONS = [
        'duplicate',
        'fraudulent',
        'requested_by_customer',
        'service_not_delivered',
        'product_not_received',
        'wrong_amount',
        'network_error',
        'transaction_error',
        'customer_complaint',
        'merchant_error',
        'reconciliation',
    ];

    private const DEFAULT_REFUND_REASON = 'requested_by_customer';

    /** @var string */
    private $secretKey;

    /** @var bool */
    private $sandbox;

    /** @var Transport */
    private $transport;

    /** @var string */
    private $userAgent;

    /** @var Logger|null */
    private $logger;

    /** @var callable(int): void */
    private $pause;

    /** @var string */
    private $baseUrl;

    /**
     * @param string $secretKey the key of the environment `sandbox` says, or '' when it has none
     * @param string $userAgent self::userAgent(): the Dashboard's and the API's logs name the module and the shop's versions
     * @param (callable(int): void)|null $pause waits that many milliseconds before a retry; the tests wait none
     * @param string $baseUrl the API's address: Wajub's, but for a stand-in a developer points it to (Services::apiUrl())
     */
    public function __construct(string $secretKey, bool $sandbox, Transport $transport, string $userAgent, ?Logger $logger = null, ?callable $pause = null, string $baseUrl = self::BASE_URL)
    {
        $this->secretKey = self::normalizeKey($secretKey);
        $this->sandbox = $sandbox;
        $this->transport = $transport;
        $this->userAgent = $userAgent;
        $this->logger = $logger;
        $this->pause = $pause ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** "Wajub-PrestaShop/1.0.0 PrestaShop/8.2.8 PHP/8.1.29": every request the module sends says it. */
    public static function userAgent(string $moduleVersion, string $prestashopVersion): string
    {
        return sprintf('Wajub-PrestaShop/%s PrestaShop/%s PHP/%s', $moduleVersion, $prestashopVersion, PHP_VERSION);
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    /** Whether this client sends a Sandbox key: the environment its key was chosen for, never the other's. */
    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    // --- Payments -------------------------------------------------------------

    /**
     * A payment the payer pays on Wajub's hosted page (`POST /payments`):
     * "Other payment methods", or the Mobile Money and Card methods when
     * their form cannot load. `accept` restricts what it may be paid with;
     * one the API would refuse is left out rather than refusing the whole
     * payment, and logged.
     *
     * @param array<string, mixed> $params amount, currency, reference; description, callback, return_url, customer (name, email, phone), metadata, accept
     *
     * @return array<string, mixed> the API's answer; parsePaymentResponse() reads it
     *
     * @throws \InvalidArgumentException when the amount, the currency, the reference, or both the e-mail and the phone are missing
     * @throws ApiException
     */
    public function createPayment(array $params): array
    {
        $currency = strtoupper(Text::code($params['currency'] ?? '', 3));
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Missing required parameter: currency');
        }
        $amount = Amount::normalize((float) ($params['amount'] ?? 0), $currency);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero');
        }
        $reference = Text::line($params['reference'] ?? '');
        if ($reference === '' || mb_strlen($reference) > 128) {
            throw new \InvalidArgumentException('The reference must be 1 to 128 characters long');
        }

        $payload = [
            'amount' => $amount,
            'currency' => $currency,
            'description' => self::description($params['description'] ?? ''),
            'reference' => $reference,
            'customer' => self::customer(is_array($params['customer'] ?? null) ? $params['customer'] : []),
        ];

        $callback = Text::url($params['callback'] ?? '');
        if ($callback !== '') {
            $payload['callback'] = $callback;
        }
        $returnUrl = Text::url($params['return_url'] ?? '');
        if ($returnUrl !== '') {
            $payload['return_url'] = $returnUrl;
        }

        $metadata = self::stringMetadata($params['metadata'] ?? []);
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        if (!empty($params['accept'])) {
            $accept = self::acceptOf($params['accept']);
            if ($accept === null) {
                $this->logError('Payment restriction left out: it is not one the API takes', ['accept' => $params['accept']]);
            } elseif ($accept !== []) {
                $payload['accept'] = $accept;
            }
        }

        return $this->request('POST', '/payments', $payload);
    }

    /**
     * A payment prepared for the payment form of a checkout, before its
     * order exists (`POST /payments` with `deferred: true`): the form can
     * load it, and nobody can pay it until activatePayment() names the order.
     *
     * Its `accept` is fixed now: the API takes it at preparation only. It
     * carries no customer or callback: those belong to the order, which
     * activation sends.
     *
     * @param array<string, mixed> $params amount, currency, reference; description, accept, expires_in (minutes), metadata
     *
     * @return array{id: string, reference: string, session_id: string, authorization_url: string, status: string, expires_at: int}
     *
     * @throws \InvalidArgumentException when a parameter is missing or not one the API takes, or `accept` restricts nothing
     * @throws ApiException InvalidRequestException (422) when the amount is out of the currency's limits, or `accept` leaves no payment method
     */
    public function preparePayment(array $params): array
    {
        $currency = strtoupper(Text::code($params['currency'] ?? '', 3));
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Missing required parameter: currency');
        }
        $amount = Amount::normalize((float) ($params['amount'] ?? 0), $currency);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero');
        }
        $reference = Text::line($params['reference'] ?? '');
        if ($reference === '' || mb_strlen($reference) > 128) {
            throw new \InvalidArgumentException('The reference must be 1 to 128 characters long');
        }
        $expiresIn = (int) ($params['expires_in'] ?? self::PREPARED_EXPIRES_IN);
        if ($expiresIn < self::EXPIRES_IN_MIN || $expiresIn > self::EXPIRES_IN_MAX) {
            throw new \InvalidArgumentException(sprintf('expires_in must be between %d and %d minutes', self::EXPIRES_IN_MIN, self::EXPIRES_IN_MAX));
        }

        $payload = [
            'amount' => $amount,
            'currency' => $currency,
            'deferred' => true,
            'reference' => $reference,
            'description' => self::description($params['description'] ?? ''),
            'expires' => ['in' => $expiresIn],
        ];

        // Unlike createPayment(), a restriction the API would refuse is not
        // left out, nor is one that restricts nothing: the payment form of
        // one kind (Mobile Money, or cards) must never be handed a payment
        // any method can pay.
        if (array_key_exists('accept', $params)) {
            $accept = self::acceptOf($params['accept']);
            if ($accept === null || $accept === []) {
                throw new \InvalidArgumentException('accept is not a restriction the API takes');
            }
            $payload['accept'] = $accept;
        }

        $metadata = self::stringMetadata($params['metadata'] ?? []);
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        // Counted from before the request: the API counts from when it
        // receives it, so this never outlives the payment.
        $sentAt = time();
        $response = $this->request('POST', '/payments', $payload, null, self::CHECKOUT_TIMEOUT);

        return self::parsePaymentResponse($response) + ['expires_at' => $sentAt + $expiresIn * 60];
    }

    /**
     * The new amount of a prepared payment, while its checkout is open
     * (`PATCH /payments/{id}`): the payer changed the cart, the delivery or
     * the country. With `$expiresIn`, the payment also lives that long again
     * from now, so a checkout left open keeps the same payment.
     *
     * @return array<string, mixed> the payment, as the API now has it
     *
     * @throws \InvalidArgumentException
     * @throws ApiException NotFoundException (404) for a payment that was not prepared, 409 once it was activated, cancelled or expired, InvalidRequestException (422) when the amount is out of limits or `accept` leaves no method for it
     */
    public function updatePreparedPayment(string $paymentId, float $amount, string $currency, ?int $expiresIn = null): array
    {
        $paymentId = Text::code($paymentId);
        $currency = strtoupper(Text::code($currency, 3));
        if ($paymentId === '' || strlen($currency) !== 3) {
            throw new \InvalidArgumentException('A payment id and a currency are required');
        }
        $amount = Amount::normalize($amount, $currency);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero');
        }

        $body = ['amount' => $amount, 'currency' => $currency];
        if ($expiresIn !== null) {
            if ($expiresIn < self::EXPIRES_IN_MIN || $expiresIn > self::EXPIRES_IN_MAX) {
                throw new \InvalidArgumentException(sprintf('expires_in must be between %d and %d minutes', self::EXPIRES_IN_MIN, self::EXPIRES_IN_MAX));
            }
            $body['expires'] = ['in' => $expiresIn];
        }

        return self::extractTransaction($this->request('PATCH', '/payments/' . rawurlencode($paymentId), $body, null, self::CHECKOUT_TIMEOUT));
    }

    /**
     * Turns a prepared payment into the order's payment
     * (`POST /payments/{id}/activate`): the payment form can take it from
     * now on, and `payment.created` is sent.
     *
     * The API remembers a hash of the body: the same body sent again answers
     * that the payment is activated, any other body is refused (409). So the
     * body is always built the same way from the same order (activationBody()),
     * and a double submit, or a retry after a lost answer, activates nothing
     * twice and is not refused.
     *
     * @param array<string, mixed> $params amount, currency, customer (email, phone, name); callback, metadata, reference, description
     *
     * @throws \InvalidArgumentException
     * @throws ApiException 422 when the amount or currency is no longer the prepared one, 409 when the payment was cancelled, expired, or activated with another body, 404 when it is not there
     */
    public function activatePayment(string $paymentId, array $params): void
    {
        $paymentId = Text::code($paymentId);
        if ($paymentId === '') {
            throw new \InvalidArgumentException('Payment id is required');
        }

        $response = $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/activate', self::activationBody($params), null, self::CHECKOUT_TIMEOUT);

        // The API answers `{"activated": true}` and nothing else. Any other
        // answer is not taken as an activation: the caller sends the same
        // body again, which is safe.
        if (($response['activated'] ?? null) !== true) {
            throw new ApiException('Wajub did not confirm that the payment was activated.', 'activation_unconfirmed', 0, $response);
        }
    }

    /**
     * The body of an activation, the same for the same order every time:
     * keys always in this order, the amount as the currency counts it, the
     * currency in upper case, the customer's email, phone and name with the
     * empty ones left out, no callback when there is none, the metadata as
     * text by key, then the reference and the description, each left out
     * when empty.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function activationBody(array $params): array
    {
        $currency = strtoupper(Text::code($params['currency'] ?? '', 3));
        $amount = Amount::normalize((float) ($params['amount'] ?? 0), $currency);
        if (strlen($currency) !== 3 || $amount <= 0) {
            throw new \InvalidArgumentException('An amount and a currency are required');
        }

        $customer = self::customer(is_array($params['customer'] ?? null) ? $params['customer'] : []);
        $body = [
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customer,
        ];

        $callback = Text::url($params['callback'] ?? '');
        if ($callback !== '') {
            $body['callback'] = $callback;
        }

        $metadata = self::stringMetadata($params['metadata'] ?? []);
        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        $reference = Text::line($params['reference'] ?? '');
        if (mb_strlen($reference) > 128) {
            throw new \InvalidArgumentException('The reference must be 128 characters long at most');
        }
        if ($reference !== '') {
            $body['reference'] = $reference;
        }

        $description = Text::line($params['description'] ?? '', 500);
        if ($description !== '') {
            $body['description'] = $description;
        }

        return $body;
    }

    /**
     * The channels a payment of this currency and amount could be paid with
     * (`GET /payments/options`): what the account offers, for the checkout to
     * show only the methods that can take it. Asked once, three seconds at
     * most: a page that cannot learn it does without, rather than wait on a
     * second try.
     *
     * @param array<string, mixed> $accept the same restriction a payment takes; one the API would refuse is left out
     *
     * @return list<array<string, mixed>> each channel: id, slug, name, type, logo, countries, and operators for an aggregate
     */
    public function paymentOptions(string $currency, float $amount, string $country = '', array $accept = []): array
    {
        $currency = strtoupper(Text::code($currency, 3));
        $query = [
            'currency' => $currency,
            'amount' => Amount::normalize($amount, $currency),
        ];

        $country = strtoupper(Text::line($country));
        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $query['country'] = $country;
        }

        $accept = self::acceptOf($accept);
        if ($accept !== null && $accept !== []) {
            $query['accept'] = $accept;
        }

        $response = $this->request('GET', '/payments/options', null, $query, self::OPTIONAL_TIMEOUT, 1);
        $channels = $response['channels'] ?? [];

        return array_values(array_filter(is_array($channels) ? $channels : [], 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    public function retrievePayment(string $paymentId): array
    {
        $paymentId = Text::code($paymentId);
        if ($paymentId === '') {
            throw new \InvalidArgumentException('Payment id is required');
        }

        return $this->request('GET', '/payments/' . rawurlencode($paymentId));
    }

    /**
     * Where a payment stands, for a payer coming back from paying, or a check
     * of an order. Read by the Wajub payment id: the API does not find a
     * payment by the merchant's reference. `pending` covers a payment that
     * is not final yet, a missing id and a read that failed: none of them
     * says the payment failed, and the webhook settles the order. A read
     * Wajub did not answer says so with `unreachable`; one Wajub answers
     * with a 404 (no such payment for this key: made in the other
     * environment, or with another account) with `missing`.
     *
     * @return array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true, missing?: true}
     */
    public function paymentOutcome(string $paymentId): array
    {
        $pending = ['outcome' => self::OUTCOME_PENDING, 'status' => '', 'transaction' => []];
        $paymentId = Text::code($paymentId);
        if ($paymentId === '') {
            return $pending;
        }

        try {
            $transaction = self::extractTransaction($this->retrievePayment($paymentId));
        } catch (\Throwable $e) {
            $this->logError('Payment lookup failed', ['payment' => $paymentId, 'error' => $e->getMessage()]);
            if ($e instanceof NotFoundException) {
                return $pending + ['missing' => true];
            }

            return self::unanswered($e) ? $pending + ['unreachable' => true] : $pending;
        }

        $status = strtolower(Text::code($transaction['status'] ?? '', 32));
        $outcome = self::OUTCOME_PENDING;
        if (self::isSucceededStatus($status)) {
            $outcome = self::OUTCOME_SUCCEEDED;
        } elseif (self::isFailedStatus($status)) {
            $outcome = self::OUTCOME_FAILED;
        }

        return ['outcome' => $outcome, 'status' => $status, 'transaction' => $transaction];
    }

    /**
     * Cancels a payment nobody has paid yet (`DELETE /payments/{id}`).
     *
     * @return array<string, mixed>
     */
    public function cancelPayment(string $paymentId): array
    {
        $paymentId = Text::code($paymentId);
        if ($paymentId === '') {
            throw new \InvalidArgumentException('Payment id is required');
        }

        return $this->request('DELETE', '/payments/' . rawurlencode($paymentId));
    }

    /**
     * Ends a payment paid in instalments before its last one (`partial`
     * only): `keep` keeps what its instalments collected and makes it
     * `succeeded` for that, `refund` gives it all back. Wajub refuses it
     * (409) while an instalment is in progress (`payment_in_progress`), for
     * a payment that is not between two instalments (`payment_not_partial`),
     * and once it was closed the other way (`payment_already_closed`).
     *
     * @return array<string, mixed>
     */
    public function closePayment(string $paymentId, string $outcome = 'keep'): array
    {
        $paymentId = Text::code($paymentId);
        if ($paymentId === '') {
            throw new \InvalidArgumentException('Payment id is required');
        }

        return $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/close', [
            'outcome' => $outcome === 'refund' ? 'refund' : 'keep',
        ]);
    }

    /**
     * The one payment that carries `reference`, the reference the module
     * sent when it created it (`GET /payments?reference=`), for a payment
     * whose id was lost. Two are asked for: enough to tell one from several.
     *
     * @throws PaymentLookupException when no payment carries it, or several do
     * @throws ApiException
     */
    public function paymentIdByReference(string $reference): string
    {
        $reference = Text::line($reference);
        if ($reference === '') {
            throw new \InvalidArgumentException('Payment reference is required');
        }
        if (self::looksLikeWajubPaymentId($reference)) {
            return $reference;
        }

        $response = $this->request('GET', '/payments', null, ['reference' => $reference, 'per_page' => 2]);
        $items = $response['items'] ?? $response['data'] ?? [];
        $ids = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $id = is_array($item) ? Text::code($item['id'] ?? '') : '';
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            throw new PaymentLookupException(PaymentLookupException::NONE, $reference);
        }
        if (count($ids) > 1) {
            throw new PaymentLookupException(PaymentLookupException::SEVERAL, $reference);
        }

        return $ids[0];
    }

    // --- Refunds --------------------------------------------------------------

    /**
     * A refund of one payment (`POST /refunds`). `$idempotencyKey`, derived
     * from the one refund the shop asks for (Refund\RefundSync), makes the
     * call safe to send again: Wajub keeps the key on the refund for good,
     * and answers it with the same refund, never a second one. Without one,
     * each call gets a key of its own, the same for its retries only.
     *
     * @param string $paymentId Wajub payment id (`trx_…`), not the merchant reference
     * @param string|null $reason one of the API's reasons, or the merchant's own words, kept as metadata
     * @param array<string, string> $metadata carried by the refund, and by its webhooks
     *
     * @return array<string, mixed>
     */
    public function createRefund(string $paymentId, ?float $amount, ?string $reason, string $currency, array $metadata = [], string $idempotencyKey = ''): array
    {
        $paymentId = Text::code($paymentId);
        if ($paymentId === '') {
            throw new \InvalidArgumentException('Payment id is required for a refund');
        }

        $payload = ['payment' => $paymentId];
        if ($amount !== null && $amount > 0) {
            $payload['amount'] = Amount::normalize($amount, $currency);
        }

        // The API requires a reason from its own list. PrestaShop gives the
        // merchant's free text, or nothing: the text travels as metadata.
        $note = $reason !== null ? Text::line($reason, 500) : '';
        $payload['reason'] = self::refundReason($note);
        if ($note !== '' && $note !== $payload['reason']) {
            $metadata['note'] = $note;
        }
        $metadata = self::stringMetadata($metadata);
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $idempotencyKey = preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $idempotencyKey) === 1 ? $idempotencyKey : '';

        return $this->request('POST', '/refunds', $payload, null, self::TIMEOUT, self::MAX_ATTEMPTS, $idempotencyKey);
    }

    /**
     * Every refund of a payment, newest first (`GET /payments/{id}/refunds`).
     *
     * @return list<array<string, mixed>>
     */
    public function paymentRefunds(string $paymentId): array
    {
        $paymentId = Text::code($paymentId);
        if ($paymentId === '') {
            throw new \InvalidArgumentException('Payment id is required');
        }

        $response = $this->request('GET', '/payments/' . rawurlencode($paymentId) . '/refunds');
        $items = $response['items'] ?? $response['data'] ?? [];

        return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
    }

    /**
     * The reason sent to POST /refunds: the given one when the API knows it,
     * `requested_by_customer` otherwise.
     */
    public static function refundReason(string $reason): string
    {
        $reason = strtolower(trim($reason));

        return in_array($reason, self::REFUND_REASONS, true) ? $reason : self::DEFAULT_REFUND_REASON;
    }

    // --- Keys and webhooks ------------------------------------------------------

    /**
     * Who the key belongs to, and the environment it works in: `GET /`, the
     * cheapest authenticated call the API has. Throws the API's refusal
     * (AuthenticationException for a key it does not know) or the network
     * error, so the caller can tell "refused" from "could not ask".
     *
     * @return array{business: string, environment: string} environment is 'sandbox' or 'live'
     */
    public function checkKey(): array
    {
        $response = $this->request('GET', '/', null, null, self::CHECK_TIMEOUT);

        return [
            'business' => Text::line($response['business'] ?? '', 255),
            // The API says `production` for a live key.
            'environment' => ($response['env'] ?? '') === 'sandbox' ? 'sandbox' : 'live',
        ];
    }

    /**
     * Revokes the key this client uses (`DELETE /keys/current`): the key a
     * pairing minted for this shop, once another replaced it. The refusal is
     * told by its status alone, never by its message.
     *
     * @throws ApiException 409 when the API does not let this key revoke itself (a key it does not know as paired), AuthenticationException (401) when the key is already gone
     */
    public function revokeCurrentKey(): void
    {
        $this->request('DELETE', '/keys/current', null, null, self::CHECK_TIMEOUT);
    }

    /**
     * The webhook endpoints of the key's environment (`GET /webhooks`), the
     * ones whose URL or description contains `search` when it is given. The
     * list never carries a signing secret.
     *
     * @return list<array<string, mixed>>
     */
    public function listWebhookEndpoints(string $search = ''): array
    {
        $query = ['per_page' => 100];
        if ($search !== '') {
            $query['search'] = $search;
        }
        $response = $this->request('GET', '/webhooks', null, $query, self::CHECK_TIMEOUT);
        $items = $response['items'] ?? $response['data'] ?? [];

        return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
    }

    /**
     * Subscribes `url` to `events` in the key's environment (`POST /webhooks`).
     * The endpoint the API answers carries its signing secret (`secret`,
     * `whsec_test_…` or `whsec_…`), shown this once. The API refuses (422) a
     * URL already registered in the environment, one that points to a
     * private address, and a non-https URL with a live key.
     *
     * @param list<string> $events
     *
     * @return array<string, mixed>
     */
    public function createWebhookEndpoint(string $url, array $events, string $description = ''): array
    {
        $payload = [
            'url' => Text::url($url),
            'events' => array_map('strval', $events),
        ];
        $description = Text::line($description, 500);
        if ($description !== '') {
            $payload['description'] = $description;
        }

        return self::endpointOf($this->request('POST', '/webhooks', $payload, null, self::CHECK_TIMEOUT));
    }

    /**
     * Changes an endpoint (`PUT /webhooks/{id}`): `events` replaces the whole
     * list, `active` turns deliveries back on or off.
     *
     * @param array{events?: list<string>, active?: bool, description?: string} $fields
     *
     * @return array<string, mixed>
     */
    public function updateWebhookEndpoint(string $endpointId, array $fields): array
    {
        $endpointId = Text::code($endpointId);
        if ($endpointId === '') {
            throw new \InvalidArgumentException('Webhook endpoint id is required');
        }

        return self::endpointOf($this->request('PUT', '/webhooks/' . rawurlencode($endpointId), $fields, null, self::CHECK_TIMEOUT));
    }

    /**
     * A new signing secret for an endpoint (`POST /webhooks/{id}/rotate-secret`).
     * The old one stops working at once, so the caller stores the new one.
     *
     * @return array<string, mixed> the endpoint, with its new `secret`
     */
    public function rotateWebhookSecret(string $endpointId): array
    {
        $endpointId = Text::code($endpointId);
        if ($endpointId === '') {
            throw new \InvalidArgumentException('Webhook endpoint id is required');
        }

        return self::endpointOf($this->request('POST', '/webhooks/' . rawurlencode($endpointId) . '/rotate-secret', [], null, self::CHECK_TIMEOUT));
    }

    // --- Answers ----------------------------------------------------------------

    /**
     * The fields the module keeps of a payment it created.
     *
     * @param array<string, mixed> $response
     *
     * @return array{id: string, reference: string, session_id: string, authorization_url: string, status: string}
     */
    public static function parsePaymentResponse(array $response): array
    {
        $transaction = self::extractTransaction($response);

        $id = Text::code($transaction['id'] ?? '');
        $reference = Text::line($transaction['reference'] ?? $transaction['trxref'] ?? $id, 128);
        $sessionId = Text::line($response['authorization_token'] ?? $transaction['authorization_token'] ?? $reference, 255);
        // The payer's browser is sent there: an address that is no https
        // page (javascript:, data:, plain http, relative) is never followed,
        // and the payment's own page on pay.wajub.com is used instead.
        $authorizationUrl = self::paymentPageUrl($response['authorization_url'] ?? $transaction['authorization_url'] ?? '');
        if ($authorizationUrl === '' && ($id !== '' || $reference !== '')) {
            $authorizationUrl = 'https://pay.wajub.com/' . rawurlencode($id !== '' ? $id : $reference);
        }

        return [
            'id' => $id,
            'reference' => $reference,
            'session_id' => $sessionId,
            'authorization_url' => $authorizationUrl,
            'status' => Text::code($transaction['status'] ?? '', 32) ?: 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public static function extractTransaction(array $response): array
    {
        $transaction = $response['transaction'] ?? $response;

        return is_array($transaction) ? $transaction : [];
    }

    public static function looksLikeWajubPaymentId(string $value): bool
    {
        return preg_match('/^(trx_|pay_)/', $value) === 1;
    }

    public static function isSucceededStatus(string $status): bool
    {
        return in_array(strtolower($status), ['succeeded', 'complete', 'paid'], true);
    }

    public static function isFailedStatus(string $status): bool
    {
        return in_array(strtolower($status), ['failed', 'canceled', 'cancelled', 'abandoned', 'rejected', 'expired'], true);
    }

    /**
     * Whether a call failed without Wajub answering it: no answer at all, a
     * rate limit, or a server error. Anything else is Wajub's answer.
     */
    public static function unanswered(\Throwable $error): bool
    {
        return $error instanceof ApiException
            && ($error->httpStatus === 0 || $error->httpStatus === 429 || $error->httpStatus >= 500);
    }

    // --- Requests ---------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>|null $query
     * @param int $attempts how often it is sent at most: one a server error (5xx), a rate limit (429) or no answer met is sent again until then, under the same idempotency key
     * @param string $idempotencyKey the caller's key for a request it may send again in another call, '' for one of this call's own
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?array $query = null, int $timeout = self::TIMEOUT, int $attempts = self::MAX_ATTEMPTS, string $idempotencyKey = ''): array
    {
        if ($this->secretKey === '') {
            throw new ApiException('No Wajub secret key is saved for this environment.', 'not_configured');
        }

        $url = $this->baseUrl . $path;
        if ($query !== null && $query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization' => $this->secretKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        // One key for every attempt: a retry after a lost answer is the same
        // request to the API, which creates nothing twice.
        if (!in_array($method, ['GET', 'DELETE'], true)) {
            $headers['Idempotency-Key'] = $idempotencyKey !== '' ? $idempotencyKey : self::IDEMPOTENCY_PREFIX . '-' . bin2hex(random_bytes(16)) . '-' . time();
        }

        $payload = null;
        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $payload = (string) json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $last = null;
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $isLast = $attempt === $attempts - 1;
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $timeout);
            } catch (TransportException $e) {
                $last = new ApiConnectionException($e->getMessage(), 'network_error');
                if (!$isLast) {
                    ($this->pause)(100 * (2 ** $attempt));
                }

                continue;
            }

            $data = $response->json();
            if ($data === null) {
                $data = ['message' => $response->body !== '' ? mb_substr($response->body, 0, 500) : 'Unknown error', 'code' => $response->status];
            }

            if ($response->status === 429 && !$isLast) {
                $retryAfter = (int) $response->header('retry-after');
                ($this->pause)(1000 * min(max($retryAfter, 1), 2 ** $attempt));

                continue;
            }
            if ($response->status >= 500 && !$isLast) {
                ($this->pause)(100 * (2 ** $attempt));

                continue;
            }
            // A redirect is an error too: it is never followed, so the key
            // never leaves for another address.
            if ($response->status < 200 || $response->status >= 300) {
                throw self::exceptionFromResponse($response->status, $data);
            }

            $this->logInfo('API request', ['method' => $method, 'path' => $path, 'code' => $response->status]);

            return $data;
        }

        throw $last ?? new ApiConnectionException('API request failed', 'network_error');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function exceptionFromResponse(int $code, array $data): ApiException
    {
        $message = is_string($data['message'] ?? null) ? $data['message'] : 'API request failed';
        $errorCode = is_string($data['error_code'] ?? null)
            ? $data['error_code']
            : (is_string($data['code'] ?? null) ? $data['code'] : 'http_' . $code);

        switch (true) {
            case $code === 401:
                return new AuthenticationException($message, $errorCode, $code, $data);
            case $code === 403:
                return new PermissionException($message, $errorCode, $code, $data);
            case $code === 404:
                return new NotFoundException($message, $errorCode, $code, $data);
            case $code === 429:
                return new RateLimitException($message, $errorCode, $code, $data);
            case $code === 400 || $code === 422:
                return new InvalidRequestException($message, $errorCode, $code, $data);
            default:
                return new ApiException($message, $errorCode, $code, $data);
        }
    }

    /**
     * What was pasted, as the API wants it: no surrounding whitespace, no
     * `Bearer ` copied from a curl example.
     */
    private static function normalizeKey(string $raw): string
    {
        return trim((string) preg_replace('/^bearer\s+/i', '', trim($raw)));
    }

    private static function description(mixed $value): string
    {
        $description = Text::line($value, 500);

        return $description !== '' ? $description : 'Payment';
    }

    /**
     * The customer as the API takes it: the empty fields left out, the phone
     * as digits and a leading `+` only. The caller gives the phone in its
     * international form; the module never guesses a country for it.
     *
     * @param array<string, mixed> $given
     *
     * @return array<string, string>
     */
    private static function customer(array $given): array
    {
        $customer = array_filter([
            'email' => Text::email($given['email'] ?? ''),
            'phone' => (string) preg_replace('/[^0-9+]/', '', Text::line($given['phone'] ?? '')),
            'name' => Text::line($given['name'] ?? '', 255),
        ], static function (string $value): bool {
            return $value !== '';
        });
        if (!isset($customer['email']) && !isset($customer['phone'])) {
            throw new \InvalidArgumentException('Customer email or phone is required');
        }

        return $customer;
    }

    /**
     * Metadata as the API keeps it: text values only, by key, in key order so
     * the same values always make the same body. A value that is not text, a
     * number or a boolean is left out.
     *
     * @return array<string, string>
     */
    private static function stringMetadata(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }

        $text = [];
        foreach ($metadata as $key => $value) {
            if (is_scalar($value)) {
                $text[(string) $key] = Text::line(is_bool($value) ? ($value ? '1' : '0') : (string) $value, 500);
            }
        }
        ksort($text, SORT_STRING);

        return $text;
    }

    /**
     * An `accept` restriction as the API takes it (PaymentAcceptance::rules()):
     * `countries`, `channels` and `types` as lists of codes, `currencies` as
     * `payment` or `any`. Codes are folded as the API folds them, empty lists
     * dropped. Null when it is not one the API would take: not an array, a
     * key it does not know (a typo would otherwise restrict nothing), a list
     * too long, or an entry that is not a code.
     *
     * @return array<string, list<string>|string>|null [] for a restriction that restricts nothing
     */
    public static function acceptOf(mixed $accept): ?array
    {
        if (!is_array($accept)) {
            return null;
        }

        $normalized = [];
        foreach ($accept as $key => $value) {
            // The API takes a key set to null as one left out.
            if ($value === null && is_string($key) && ($key === 'currencies' || isset(self::ACCEPT_LISTS[$key]))) {
                continue;
            }
            if ($key === 'currencies') {
                if (!in_array($value, ['payment', 'any'], true)) {
                    return null;
                }
                $normalized['currencies'] = $value;

                continue;
            }

            if (!is_string($key) || !isset(self::ACCEPT_LISTS[$key])) {
                return null;
            }
            [$most, $longest, $pattern] = self::ACCEPT_LISTS[$key];
            if (!is_array($value) || !array_is_list($value) || count($value) > $most) {
                return null;
            }

            $codes = [];
            foreach ($value as $code) {
                if (!is_string($code) || strlen(trim($code)) > $longest || preg_match($pattern, trim($code)) !== 1) {
                    return null;
                }
                $codes[] = $key === 'countries' ? strtoupper(trim($code)) : strtolower(trim($code));
            }
            if ($codes !== []) {
                $normalized[$key] = array_values(array_unique($codes));
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private static function endpointOf(array $response): array
    {
        $endpoint = $response['endpoint'] ?? $response['data'] ?? $response;

        return is_array($endpoint) ? $endpoint : [];
    }

    /**
     * $url when it is an https address with a host, else ''. Nor one with a
     * user before its host (https://pay.wajub.com@elsewhere/), which is not
     * the host it seems to name.
     */
    private static function paymentPageUrl(mixed $url): string
    {
        if (!is_string($url)) {
            return '';
        }
        $url = trim($url);

        return preg_match('#^https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?(?:[/?\#]|$)#i', $url) === 1 ? $url : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logInfo(string $message, array $context): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logError(string $message, array $context): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message, $context);
        }
    }
}
