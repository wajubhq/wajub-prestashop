<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

/*
 * Wajub's API in memory, for the money lot's runners (remainder, refunds,
 * cleanup): payments read, cancelled and closed as Wajub does, refunds made
 * and listed (one asked again under its idempotency key answered with the
 * refund made under it), and any call answered otherwise for one test
 * (`next`). Each
 * call is recorded as "METHOD /path", with its body. A port of the WordPress
 * plugin's tests/run-remainder-tests.php stand-in.
 *
 * Reachable as $GLOBALS['api'] once money_shop() built it.
 */

use Wajub\PrestaShop\Checkout\Tokens;
use Wajub\PrestaShop\Http\HttpResponse;
use Wajub\PrestaShop\Http\Transport;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Support\PlainMoneyFormatter;

final class FakeWajubApi implements Transport
{
    /** @var array<string, array<string, mixed>> by payment id */
    public array $payments = [];

    /** @var array<string, list<array<string, mixed>>> by payment id */
    public array $refunds = [];

    /** @var array<string, list<mixed>> "METHOD /path" => answers given in turn before the API's own: [status, body] or a Throwable */
    public array $next = [];

    /** @var list<array{route: string, body: mixed, headers: array<string, string>}> */
    public array $sent = [];

    /** @var list<array<string, mixed>> the refunds made */
    public array $made = [];

    /** @var array<string, array<string, mixed>> the refunds made, by the idempotency key they were asked under */
    public array $refundsByKey = [];

    /** @var (callable(array<string, mixed>): void)|null run with each refund made, before its answer */
    public $duringRefund;

    private int $created = 0;

    private int $refundCount = 0;

    /**
     * A payment of the order `$order`, as Wajub reads it: in `$sandbox` (Live
     * unless said), 10 000 FCFA, succeeded unless `$fields` says otherwise.
     *
     * @param array<string, mixed> $fields
     */
    public function add(string $id, array $fields = [], bool $sandbox = false): void
    {
        $status = (string) ($fields['status'] ?? 'succeeded');
        $amount = $fields['amount'] ?? 10000;
        $this->payments[$id] = $fields + [
            'id' => $id,
            'status' => $status,
            'amount' => $amount,
            'amount_paid' => in_array($status, ['succeeded', 'partially_refunded', 'refunded'], true) ? $amount : 0,
            'currency' => 'XAF',
            'sandbox' => $sandbox,
            'channel' => 'cm.mtn',
        ];
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $route = $method . ' ' . $path;
        $json = $body !== null ? json_decode($body, true) : null;
        $this->sent[] = ['route' => $route, 'body' => $json, 'headers' => $headers];

        if (isset($this->next[$route]) && $this->next[$route] !== []) {
            $answer = array_shift($this->next[$route]);
            if ($answer instanceof Throwable) {
                throw $answer;
            }

            return self::response($answer[0], $answer[1]);
        }

        $sandbox = str_starts_with((string) ($headers['Authorization'] ?? ''), 'sk_test.');
        if ($route === 'POST /payments') {
            $id = ($sandbox ? 'trx_test_new_' : 'trx_new_') . ++$this->created;
            $this->payments[$id] = [
                'id' => $id,
                'status' => 'pending',
                'amount' => $json['amount'] ?? 0,
                'amount_paid' => 0,
                'currency' => $json['currency'] ?? 'XAF',
                'sandbox' => $sandbox,
                'metadata' => $json['metadata'] ?? [],
                'reference' => $json['reference'] ?? '',
            ];

            return self::response(201, ['transaction' => $this->payments[$id], 'authorization_url' => 'https://pay.wajub.com/' . $id, 'authorization_token' => 'tok_' . $id]);
        }
        if ($route === 'POST /refunds') {
            // A key Wajub knows: the refund it made under it, never a second one.
            $key = (string) ($headers['Idempotency-Key'] ?? '');
            if ($key !== '' && isset($this->refundsByKey[$key])) {
                return self::response(201, ['refund' => $this->refundsByKey[$key]]);
            }
            $paymentId = (string) ($json['payment'] ?? '');
            $refund = [
                'id' => 'rfd_' . ++$this->refundCount,
                'transaction' => $paymentId,
                'amount' => $json['amount'] ?? ($this->payments[$paymentId]['amount_paid'] ?? 0),
                'currency' => $this->payments[$paymentId]['currency'] ?? 'XAF',
                'status' => $sandbox ? 'processing' : 'pending',
                'metadata' => $json['metadata'] ?? [],
                'sandbox' => $sandbox,
            ];
            $this->made[] = $refund;
            $this->refunds[$paymentId][] = $refund;
            if ($key !== '') {
                $this->refundsByKey[$key] = $refund;
            }
            if ($this->duringRefund !== null) {
                ($this->duringRefund)($refund);
            }

            return self::response(201, ['refund' => $refund]);
        }

        if (preg_match('#^GET /payments/([A-Za-z0-9_]+)/refunds$#', $route, $match) === 1 && !isset($this->payments[$match[1]])) {
            // Refunds of a payment the test did not describe: its own list.
            return self::response(200, ['items' => $this->refunds[$match[1]] ?? []]);
        }
        if (preg_match('#^(GET|DELETE|POST) /payments/([A-Za-z0-9_]+)(/refunds|/close)?$#', $route, $match) === 1) {
            $payment = $this->payments[$match[2]] ?? null;
            if ($payment === null || (bool) ($payment['sandbox'] ?? false) !== $sandbox) {
                return self::response(404, ['message' => 'Payment not found.', 'error_code' => 'not_found']);
            }
            $action = $match[3] ?? '';
            if ($match[1] === 'GET' && $action === '') {
                return self::response(200, ['transaction' => $payment]);
            }
            if ($match[1] === 'GET' && $action === '/refunds') {
                return self::response(200, ['items' => $this->refunds[$match[2]] ?? []]);
            }
            if ($match[1] === 'DELETE' && $action === '') {
                if ($payment['status'] !== 'pending') {
                    return self::response(409, ['message' => 'Payment cannot be cancelled.', 'error_code' => 'payment_not_cancellable']);
                }
                $this->payments[$match[2]]['status'] = 'cancelled';

                return self::response(200, ['transaction' => $this->payments[$match[2]]]);
            }
            if ($match[1] === 'POST' && $action === '/close') {
                if ($payment['status'] !== 'partial') {
                    return self::response(409, ['message' => 'Not between two instalments.', 'error_code' => 'payment_not_partial']);
                }
                $this->payments[$match[2]]['status'] = 'succeeded';

                return self::response(200, ['transaction' => $this->payments[$match[2]]]);
            }
        }
        if ($route === 'GET /payments/options') {
            return self::response(200, ['channels' => [
                ['id' => 'cm.mtn', 'slug' => 'cm.mtn', 'type' => 'mobile_money'],
                ['id' => 'card', 'slug' => 'card', 'type' => 'card'],
                ['id' => 'paypal', 'slug' => 'paypal', 'type' => 'paypal'],
            ]]);
        }

        return self::response(418, ['message' => 'No route for ' . $route]);
    }

    /**
     * The calls sent since the last calls(), as "METHOD /path".
     *
     * @return list<string>
     */
    public function sent(): array
    {
        return array_map(static fn (array $call): string => $call['route'], $this->sent);
    }

    /** Starts recording the calls anew. */
    public function calls(): void
    {
        $this->sent = [];
    }

    /**
     * The bodies of the calls sent to `$route`.
     *
     * @return list<mixed>
     */
    public function bodies(string $route): array
    {
        return array_values(array_map(static fn (array $call): mixed => $call['body'], array_filter($this->sent, static fn (array $call): bool => $call['route'] === $route)));
    }

    /**
     * The headers of the calls sent to `$route`.
     *
     * @return list<array<string, string>>
     */
    public function headers(string $route): array
    {
        return array_values(array_map(static fn (array $call): array => $call['headers'], array_filter($this->sent, static fn (array $call): bool => $call['route'] === $route)));
    }

    /** No answer at all, as cURL says it. */
    public static function timeout(): Wajub\PrestaShop\Http\TransportException
    {
        return new Wajub\PrestaShop\Http\TransportException('cURL error 28: Operation timed out');
    }

    /**
     * @param mixed $body
     */
    private static function response(int $status, $body): HttpResponse
    {
        return new HttpResponse($status, [], is_string($body) ? $body : (string) json_encode($body));
    }
}

/**
 * A shop with the money lot's services on stand-ins, Wajub's API in memory
 * ($GLOBALS['api']), keys of both environments, in `$environment` (Live
 * unless said, as the plugin's tests).
 *
 * @param array<string, mixed> $settings
 */
function money_shop(string $environment = 'live', array $settings = []): Services
{
    $s = payment_services($environment, $settings);
    $GLOBALS['api'] = new FakeWajubApi();
    $s = new Services(Scope::allShops(), '1.0.0', [
        'transport' => $GLOBALS['api'],
        'state' => $GLOBALS['store'],
        'configStore' => $s->configStore(),
        'cipher' => $s->cipher(),
        'translator' => $GLOBALS['translator'],
        'logger' => $GLOBALS['logger'],
        'shop' => $GLOBALS['shop'],
        'events' => $GLOBALS['events'],
        'pause' => static function (int $milliseconds): void {
        },
        'payments' => $GLOBALS['payments'],
        'refunds' => $GLOBALS['refunds'],
        'orders' => $GLOBALS['orders'],
        'lock' => $GLOBALS['locks'],
        'money' => new PlainMoneyFormatter(),
        'storefront' => $GLOBALS['storefront'],
        'tokens' => new Tokens('test-secret'),
    ]);
    $GLOBALS['money_shop'] = $s;

    return $s;
}

/** Switches the shop to `$environment`, its keys kept. */
function shop_in(Services $s, string $environment): void
{
    $s->settings()->setEnvironment($environment);
}

/**
 * An unpaid order of 10 000 FCFA (unless said), "Awaiting Wajub payment",
 * holding `$paymentId` (made in Live unless the id says Sandbox), which the
 * shop started on Wajub's hosted page.
 *
 * @param array<string, mixed> $fields PaymentRecord properties of its payment
 */
function money_order(int $id, string $paymentId = '', float $total = 10000.0, array $fields = []): OrderView
{
    $order = $GLOBALS['orders']->add($id, $total, 'XAF', ['reference' => 'R' . $id]);
    if ($paymentId !== '') {
        money_payment($id, $paymentId, $fields + ['amount' => $total]);
    }

    return $order;
}

/**
 * A payment the shop started for an order: on the hosted page, Mobile
 * Money's, in Live unless its id says Sandbox, with its page, started as
 * the order was placed.
 *
 * @param array<string, mixed> $fields PaymentRecord properties
 */
function money_payment(int $idOrder, string $paymentId, array $fields = []): PaymentRecord
{
    $record = new PaymentRecord();
    $record->paymentId = $paymentId;
    $record->idOrder = $idOrder;
    $record->idCart = 100 + $idOrder;
    $record->idShop = 1;
    $record->environment = str_starts_with($paymentId, 'trx_test_') ? 'sandbox' : 'live';
    $record->method = 'mobile_money';
    $record->mode = PaymentRecord::MODE_HOSTED;
    $record->state = PaymentRecord::ACTIVE;
    $record->currency = 'XAF';
    $record->amount = 10000.0;
    $record->reference = 'Order #R' . $idOrder;
    $record->setMeta('authorization_url', 'https://pay.wajub.com/' . $paymentId);
    // Started as its order was placed.
    $placed = $GLOBALS['orders']->orders[$idOrder]['placed'] ?? null;
    if (is_int($placed)) {
        $record->dateAdd = date('Y-m-d H:i:s', $placed);
    }
    foreach ($fields as $name => $value) {
        $record->{$name} = $value;
    }
    $GLOBALS['payments']->save($record);

    return $record;
}

/**
 * Counts `$paymentId` toward the order, for `$amount`, as a read of it that
 * went through would have: an OrderPayment, and the payment final.
 */
function counted(int $idOrder, string $paymentId, float $amount, string $environment = 'live'): PaymentRecord
{
    $record = $GLOBALS['payments']->find($paymentId) ?? money_payment($idOrder, $paymentId, ['environment' => $environment]);
    $record->environment = $environment;
    $record->idOrderPayment = $GLOBALS['orders']->addPayment($GLOBALS['orders']->find($idOrder), $amount, $paymentId);
    $record->counted = $amount;
    $record->amountPaid = $amount;
    $record->status = 'succeeded';
    $record->state = PaymentRecord::FINAL;
    $GLOBALS['payments']->save($record);

    return $record;
}

/** Moves the order to "Payment accepted", as a payment that covers it or the merchant would. */
function mark_paid(int $idOrder): void
{
    $GLOBALS['orders']->setState($GLOBALS['orders']->find($idOrder), FakeShopOrders::PAID, true);
}

/** "4 000 FCFA", its spaces plain. */
function fcfa(float $amount): string
{
    return spaced((new PlainMoneyFormatter())->format($amount, 'XAF'));
}

/** An amount of another currency, as the notes write it, its spaces plain. */
function money_of(float $amount, string $currency): string
{
    return spaced((new PlainMoneyFormatter())->format($amount, $currency));
}

/** The order's notes, oldest first, as one line. */
function notes_of(int $idOrder): string
{
    return $GLOBALS['orders']->notesText($idOrder);
}

/** How many of the order's notes hold `$words`. */
function noted(int $idOrder, string $words): int
{
    return $GLOBALS['orders']->notedTimes($idOrder, $words);
}

/** The calls sent to Wajub since the last calls(). */
function sent(): string
{
    return implode(', ', $GLOBALS['api']->sent());
}
