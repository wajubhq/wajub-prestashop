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
 * Stand-ins for what the payment lot reaches outside PHP: the shop's
 * payments and refunds (the module's tables), PrestaShop's orders, the
 * database's locks, the storefront's addresses and the payer's cart. Each
 * records what was done to it, for the runners to check. Reachable as
 * $GLOBALS['payments'], ['refunds'], ['orders'], ['locks'] and
 * ['storefront'] once payment_services() built them: names no runner uses
 * for a variable of its own.
 */

use Wajub\PrestaShop\Checkout\CartView;
use Wajub\PrestaShop\Checkout\Storefront;
use Wajub\PrestaShop\Checkout\Tokens;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Order\ShopOrders;
use Wajub\PrestaShop\Payment\DbRefundStore;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Payment\RefundRecord;
use Wajub\PrestaShop\Payment\RefundStore;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\Lock;
use Wajub\PrestaShop\Support\PlainMoneyFormatter;

/** Text with the thin and no-break spaces of amounts ("4\u{202F}000\u{00A0}FCFA") as plain spaces. */
function spaced(string $text): string
{
    return str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);
}

/** The `wajub_payment` table in memory. */
final class MemoryPaymentStore implements PaymentStore
{
    /** @var array<int, PaymentRecord> by row id */
    public array $rows = [];

    private int $next = 1;

    public function find(string $paymentId): ?PaymentRecord
    {
        foreach ($this->rows as $row) {
            if ($row->paymentId === $paymentId) {
                return clone $row;
            }
        }

        return null;
    }

    public function preparedFor(int $idCart, string $method): ?PaymentRecord
    {
        $found = null;
        foreach ($this->rows as $row) {
            if ($row->idCart === $idCart && $row->method === $method && $row->state === PaymentRecord::PREPARED && $row->idOrder === 0) {
                $found = $row;
            }
        }

        return $found !== null ? clone $found : null;
    }

    public function forOrder(int $idOrder): array
    {
        $records = [];
        foreach ($this->rows as $row) {
            if ($idOrder > 0 && $row->idOrder === $idOrder && $row->state !== PaymentRecord::PREPARED) {
                $records[] = clone $row;
            }
        }

        return $records;
    }

    public function current(int $idOrder): ?PaymentRecord
    {
        $records = $this->forOrder($idOrder);

        return $records === [] ? null : $records[count($records) - 1];
    }

    public function uncounted(int $limit, array $skip = []): array
    {
        $found = [];
        foreach ($this->rows as $row) {
            if ($row->state === PaymentRecord::UNCOUNTED && $row->idOrder > 0 && !in_array($row->idOrder, $skip, true)) {
                $found[] = clone $row;
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function ordersWithOpenPayments(array $idShops, int $limit): array
    {
        $orders = [];
        foreach ($this->rows as $row) {
            if ($row->idOrder > 0 && in_array($row->idShop, $idShops, true) && in_array($row->state, [PaymentRecord::ACTIVE, PaymentRecord::UNCOUNTED], true)) {
                $orders[$row->idOrder] = true;
            }
        }

        return array_slice(array_keys($orders), 0, $limit);
    }

    public function forCustomer(int $idCustomer): array
    {
        $records = [];
        foreach ($this->rows as $row) {
            if ($idCustomer > 0 && $row->idCustomer === $idCustomer && $row->idOrder > 0) {
                $records[] = clone $row;
            }
        }

        return $records;
    }

    public function forgetCustomer(int $idCustomer): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            if ($idCustomer > 0 && $row->idCustomer === $idCustomer) {
                $row->idCustomer = 0;
                ++$count;
            }
        }

        return $count;
    }

    public function save(PaymentRecord $record): void
    {
        if ($record->id === 0) {
            foreach ($this->rows as $row) {
                if ($row->paymentId === $record->paymentId) {
                    throw new RuntimeException('Duplicate payment_id ' . $record->paymentId);
                }
            }
            $record->id = $this->next++;
            // As the table writes it: when the payment started.
            if ($record->dateAdd === '') {
                $record->dateAdd = date('Y-m-d H:i:s');
            }
        }
        $this->rows[$record->id] = clone $record;
    }

    /** The row of a payment, as saved. */
    public function row(string $paymentId): ?PaymentRecord
    {
        return $this->find($paymentId);
    }
}

/** The `wajub_refund` table in memory. */
final class MemoryRefundStore implements RefundStore
{
    /** @var array<int, RefundRecord> by row id */
    public array $rows = [];

    private int $next = 1;

    public function note(PaymentRecord $payment, array $refund, string $origin, bool $orderUnpaid = false): bool
    {
        $id = DbRefundStore::refundId($refund['id'] ?? '');
        if ($id === '') {
            return false;
        }
        $status = DbRefundStore::status($refund['status'] ?? '');
        $metadata = is_array($refund['metadata'] ?? null) ? $refund['metadata'] : [];
        $own = $this->findByKey((string) ($metadata['request_key'] ?? ''));
        if ($own !== null && ($own->refundId === '' || $own->refundId === $id)) {
            $this->moveOn($own->id, $id, $status);

            return false;
        }
        $known = $this->find($id);
        if ($known !== null) {
            $this->moveOn($known->id, $id, $status);

            return false;
        }
        $record = new RefundRecord();
        $record->refundId = $id;
        $record->paymentId = $payment->paymentId;
        $record->idShop = $payment->idShop;
        $record->idOrder = $payment->idOrder;
        $record->environment = $payment->environment;
        $record->origin = $origin;
        $record->status = $status;
        $record->currency = strtoupper((string) ($refund['currency'] ?? '')) ?: $payment->currency;
        $record->amount = Amount::normalize(is_numeric($refund['amount'] ?? null) ? max(0.0, (float) $refund['amount']) : 0.0, $record->currency);
        $record->orderUnpaid = $orderUnpaid;
        $this->insert($record);

        return true;
    }

    public function refunded(string $paymentId, string $currency): float
    {
        return $this->sum($paymentId, $currency, RefundStore::LIVE);
    }

    public function succeeded(string $paymentId, string $currency): float
    {
        return $this->sum($paymentId, $currency, ['succeeded']);
    }

    public function find(string $refundId): ?RefundRecord
    {
        foreach ($this->rows as $row) {
            if ($refundId !== '' && $row->refundId === $refundId) {
                return clone $row;
            }
        }

        return null;
    }

    public function findByKey(string $requestKey): ?RefundRecord
    {
        foreach ($this->rows as $row) {
            if ($requestKey !== '' && $row->requestKey === $requestKey) {
                return clone $row;
            }
        }

        return null;
    }

    public function forOrder(int $idOrder): array
    {
        $records = [];
        foreach ($this->rows as $row) {
            if ($row->idOrder === $idOrder) {
                $records[] = clone $row;
            }
        }

        return $records;
    }

    public function requested(PaymentRecord $payment, string $requestKey, float $amount, string $reason): void
    {
        if (DbRefundStore::requestKey($requestKey) === '' || $this->findByKey($requestKey) !== null) {
            throw new RuntimeException('Bad or duplicate request_key ' . $requestKey);
        }
        $record = new RefundRecord();
        $record->requestKey = $requestKey;
        $record->paymentId = $payment->paymentId;
        $record->idShop = $payment->idShop;
        $record->idOrder = $payment->idOrder;
        $record->environment = $payment->environment;
        $record->origin = RefundRecord::ORIGIN_PRESTASHOP;
        $record->status = RefundRecord::REQUESTED;
        $record->currency = $payment->currency;
        $record->amount = Amount::normalize($amount, $payment->currency);
        $record->reason = $reason;
        $this->insert($record);
    }

    public function answered(string $requestKey, array $refund): void
    {
        $own = $this->findByKey($requestKey);
        if ($own !== null) {
            $this->moveOn($own->id, DbRefundStore::refundId($refund['id'] ?? ''), DbRefundStore::status($refund['status'] ?? ''));
        }
    }

    public function forget(string $requestKey): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row->requestKey === $requestKey && $row->refundId === '' && $row->status === RefundRecord::REQUESTED) {
                unset($this->rows[$id]);
            }
        }
    }

    public function linkSlip(RefundRecord $refund, int $idOrderSlip): void
    {
        if (isset($this->rows[$refund->id]) && $idOrderSlip > 0) {
            $this->rows[$refund->id]->idOrderSlip = $idOrderSlip;
            $refund->idOrderSlip = $idOrderSlip;
        }
    }

    /** The refund with this Wajub id, or the shop's with this key, as saved. */
    public function row(string $idOrKey): ?RefundRecord
    {
        return $this->find($idOrKey) ?? $this->findByKey($idOrKey);
    }

    private function insert(RefundRecord $record): void
    {
        $record->id = $this->next++;
        $this->rows[$record->id] = clone $record;
    }

    private function moveOn(int $id, string $refundId, string $status): void
    {
        $row = $this->rows[$id];
        if ($row->refundId === '' && $refundId !== '' && $this->find($refundId) === null) {
            $row->refundId = $refundId;
        }
        if (DbRefundStore::movesOn($row->status, $status)) {
            $row->status = $status;
        }
    }

    /**
     * @param list<string> $statuses
     */
    private function sum(string $paymentId, string $currency, array $statuses): float
    {
        $sum = 0.0;
        foreach ($this->rows as $row) {
            if ($row->paymentId === $paymentId && $row->currency === $currency && in_array($row->status, $statuses, true)) {
                $sum += $row->amount;
            }
        }

        return Amount::normalize($sum, $currency);
    }
}

/** PrestaShop's orders, states and payments, in memory. */
final class FakeShopOrders implements ShopOrders
{
    public const AWAITING = 20;

    public const PARTIAL = 21;

    public const PAID = 2;

    public const ERROR = 8;

    public const CANCELED = 6;

    public const REFUNDED = 7;

    /** The states whose `paid` flag is on. */
    public const PAID_STATES = [self::PAID, 4, 5];

    /** @var array<int, array<string, mixed>> by order id */
    public array $orders = [];

    /** @var list<array{order: int, amount: float, transaction: string, id: int}> */
    public array $payments = [];

    /** @var list<array{order: int, state: int, mail: bool}> */
    public array $history = [];

    /** @var array<int, array<string, string>> order id => key => message */
    public array $notes = [];

    /** @var list<array{order: int, amount: float, id: int, wajub: bool}> credit slips: the merchant's (wajub false) and the module's */
    public array $slips = [];

    /** An OrderPayment that cannot be added: what a shop failing mid-way does. */
    public bool $failPayments = false;

    /**
     * An order as validateOrder() leaves it, in "Awaiting Wajub payment".
     *
     * @param array<string, mixed> $overrides
     */
    public function add(int $id, float $total, string $currency = 'XAF', array $overrides = []): OrderView
    {
        $this->orders[$id] = $overrides + [
            'reference' => 'REF' . str_pad((string) $id, 6, 'X', STR_PAD_LEFT),
            'shop' => 1,
            'cart' => 100 + $id,
            'customer' => 7,
            'lang' => 1,
            'currency' => $currency,
            'total' => $total,
            'state' => self::AWAITING,
            'module' => 'wajub',
            'title' => 'Mobile Money',
            'secure_key' => 'securekey' . $id,
            'placed' => time() - 7200,
        ];
        $this->history[] = ['order' => $id, 'state' => (int) $this->orders[$id]['state'], 'mail' => false];

        return $this->find($id);
    }

    public function find(int $idOrder): ?OrderView
    {
        $o = $this->orders[$idOrder] ?? null;
        if ($o === null) {
            return null;
        }
        $paid = in_array((int) $o['state'], self::PAID_STATES, true);
        $paidOnce = $paid;
        foreach ($this->history as $entry) {
            if ($entry['order'] === $idOrder && in_array($entry['state'], self::PAID_STATES, true)) {
                $paidOnce = true;
            }
        }

        return new OrderView($idOrder, (string) $o['reference'], (int) $o['shop'], (int) $o['cart'], (int) $o['customer'], (int) $o['lang'], (string) $o['currency'], (float) $o['total'], (int) $o['state'], $paid, $paidOnce, (string) $o['module'], (string) $o['title'], (string) $o['secure_key'], [$idOrder]);
    }

    public function addPayment(OrderView $order, float $amount, string $transactionId): int
    {
        if ($this->failPayments) {
            throw new RuntimeException('The payment could not be added');
        }
        $id = count($this->payments) + 1;
        $this->payments[] = ['order' => $order->id, 'amount' => $amount, 'transaction' => $transactionId, 'id' => $id];

        return $id;
    }

    public function setState(OrderView $order, int $stateId, bool $mail): void
    {
        if ((int) $this->orders[$order->id]['state'] === $stateId) {
            return;
        }
        $this->orders[$order->id]['state'] = $stateId;
        $this->history[] = ['order' => $order->id, 'state' => $stateId, 'mail' => $mail];
    }

    public function note(OrderView $order, string $key, string $message): bool
    {
        if (isset($this->notes[$order->id][$key])) {
            return false;
        }
        $this->notes[$order->id][$key] = $message;

        return true;
    }

    public function notes(int $idOrder): array
    {
        $notes = [];
        foreach (array_reverse(array_filter($this->notes[$idOrder] ?? [], 'strlen'), true) as $key => $message) {
            $notes[] = ['key' => (string) $key, 'message' => $message, 'date' => '2026-10-14 12:00:00'];
        }

        return $notes;
    }

    public function addSlip(OrderView $order, float $amount): int
    {
        $id = count($this->slips) + 1;
        $this->slips[] = ['order' => $order->id, 'amount' => $amount, 'id' => $id, 'wajub' => true];

        return $id;
    }

    /** A credit slip the merchant made in the back office, as PrestaShop adds it. */
    public function merchantSlip(int $idOrder, float $amount): int
    {
        $id = count($this->slips) + 1;
        $this->slips[] = ['order' => $idOrder, 'amount' => $amount, 'id' => $id, 'wajub' => false];

        return $id;
    }

    public function lastSlip(OrderView $order): int
    {
        $last = 0;
        foreach ($this->slips as $slip) {
            if ($slip['order'] === $order->id) {
                $last = max($last, $slip['id']);
            }
        }

        return $last;
    }

    public function refundedOnOrder(OrderView $order): float
    {
        $sum = 0.0;
        foreach ($this->slips as $slip) {
            if ($slip['order'] === $order->id) {
                $sum += $slip['amount'];
            }
        }

        return $sum;
    }

    /** @return list<array{order: int, amount: float, id: int, wajub: bool}> the slips the module made for the order */
    public function slipsOf(int $idOrder): array
    {
        return array_values(array_filter($this->slips, static fn (array $slip): bool => $slip['order'] === $idOrder && $slip['wajub']));
    }

    public function inState(int $stateId, int $limit, array $skip = []): array
    {
        $found = [];
        foreach ($this->orders as $id => $o) {
            if ((int) $o['state'] === $stateId && $o['module'] === 'wajub' && !in_array($id, $skip, true)) {
                $found[$id] = (int) $o['placed'];
            }
        }
        asort($found);

        return array_slice($found, 0, $limit, true);
    }

    public function paidState(): int
    {
        return self::PAID;
    }

    public function errorState(): int
    {
        return self::ERROR;
    }

    public function canceledState(): int
    {
        return self::CANCELED;
    }

    public function refundedState(): int
    {
        return self::REFUNDED;
    }

    public function state(int $idOrder): int
    {
        return (int) $this->orders[$idOrder]['state'];
    }

    /** @return list<array{order: int, amount: float, transaction: string, id: int}> */
    public function paymentsOf(int $idOrder): array
    {
        return array_values(array_filter($this->payments, static fn (array $p): bool => $p['order'] === $idOrder));
    }

    /** Whether a note whose text holds `$part` was written on the order (its amounts' spaces read as spaces). */
    public function noted(int $idOrder, string $part): bool
    {
        return $this->notedTimes($idOrder, $part) > 0;
    }

    /** How many of the order's notes hold `$part`. */
    public function notedTimes(int $idOrder, string $part): int
    {
        $times = 0;
        foreach ($this->notes[$idOrder] ?? [] as $message) {
            if ($message !== '' && str_contains(spaced($message), $part)) {
                ++$times;
            }
        }

        return $times;
    }

    /** The order's notes, oldest first, as one text. */
    public function notesText(int $idOrder): string
    {
        return spaced(implode(' | ', array_filter($this->notes[$idOrder] ?? [], 'strlen')));
    }
}

/** The database's named locks: one request holds each, and `$busy` ones are another request's. */
final class MemoryLock implements Lock
{
    /** @var array<string, true> */
    public array $held = [];

    /** @var array<string, true> */
    public array $busy = [];

    /** @var list<string> every lock taken, in order */
    public array $taken = [];

    public function acquire(string $name, int $wait): bool
    {
        if (isset($this->held[$name]) || isset($this->busy[$name])) {
            return false;
        }
        $this->held[$name] = true;
        $this->taken[] = $name;

        return true;
    }

    public function release(string $name): void
    {
        unset($this->held[$name]);
    }
}

/** The storefront's addresses, made up, and the carts it gave back. */
final class FakeStorefront implements Storefront
{
    /** @var list<int> orders whose cart was given back */
    public array $restored = [];

    /** Whether the visitor's session is the order's customer's (false: a guest signed out, another browser). */
    public bool $visitorIsCustomer = true;

    /** A cart that cannot be copied. */
    public bool $copyFails = false;

    public function confirmation(OrderView $order): string
    {
        return 'https://shop.test/order-confirmation?id_order=' . $order->id . '&key=' . $order->secureKey;
    }

    public function status(OrderView $order): string
    {
        return 'https://shop.test/module/wajub/status?id_order=' . $order->id . '&token=' . $this->orderToken($order);
    }

    public function returnUrl(OrderView $order): string
    {
        return 'https://shop.test/module/wajub/return?id_order=' . $order->id . '&token=' . $this->orderToken($order);
    }

    public function release(OrderView $order): string
    {
        return 'https://shop.test/module/wajub/release?id_order=' . $order->id . '&token=' . $this->orderToken($order);
    }

    public function pay(OrderView $order): string
    {
        return 'https://shop.test/module/wajub/pay?id_order=' . $order->id . '&token=' . $this->orderToken($order);
    }

    public function orderToken(OrderView $order): string
    {
        return (new Tokens('test-secret'))->order($order->id, $order->secureKey);
    }

    public function checkout(): string
    {
        return 'https://shop.test/order';
    }

    public function canRestoreCart(OrderView $order): bool
    {
        return $this->visitorIsCustomer;
    }

    public function restoreCart(OrderView $order): bool
    {
        if (!$this->visitorIsCustomer || $this->copyFails) {
            return false;
        }
        $this->restored[] = $order->id;

        return true;
    }
}

/**
 * The module's services on stand-ins, the payment lot's included, for a shop
 * with keys of both environments, in `$environment`.
 *
 * @param array<string, mixed> $settings
 */
function payment_services(string $environment = 'sandbox', array $settings = []): Services
{
    $s = services($settings);
    $GLOBALS['payments'] = new MemoryPaymentStore();
    $GLOBALS['refunds'] = new MemoryRefundStore();
    $GLOBALS['orders'] = new FakeShopOrders();
    $GLOBALS['locks'] = new MemoryLock();
    $GLOBALS['storefront'] = new FakeStorefront();

    $s = new Services(Scope::allShops(), '1.0.0', [
        'transport' => $GLOBALS['http'],
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
    $s->settings()->setSecretKey('sandbox', key_for('sandbox'));
    $s->settings()->setSecretKey('live', key_for('live'));
    $s->settings()->setEnvironment($environment);
    (new ArrayConfigStore())->set('WAJUB_OS_AWAITING', (string) FakeShopOrders::AWAITING);
    (new ArrayConfigStore())->set('WAJUB_OS_PARTIAL', (string) FakeShopOrders::PARTIAL);

    return $s;
}

/** A cart of `$total`, by a payer in Cameroon. */
function cart_view(int $id = 501, float $total = 15000.0, string $currency = 'XAF'): CartView
{
    return new CartView($id, 1, 7, 1, $currency, $total, 'CM', 'payer@example.cm', '+237677123456', 'Amina Diallo');
}

/**
 * A payment as Wajub's API gives it (GET /payments/{id}, a webhook's data).
 *
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function payment_data(string $id, string $status = 'succeeded', float $amount = 15000.0, array $overrides = []): array
{
    return $overrides + [
        'id' => $id,
        'status' => $status,
        'amount' => $amount,
        'amount_paid' => $status === 'succeeded' ? $amount : 0,
        'currency' => 'XAF',
        'sandbox' => str_starts_with($id, 'trx_test_'),
        'channel' => 'cm.mtn',
        'reference' => 'Order #REF',
    ];
}

/**
 * A payment the shop started for an order, saved.
 *
 * @param array<string, mixed> $fields PaymentRecord properties
 */
function saved_payment(array $fields): PaymentRecord
{
    $record = new PaymentRecord();
    $record->environment = 'sandbox';
    $record->method = 'mobile_money';
    $record->mode = PaymentRecord::MODE_FIELDS;
    $record->state = PaymentRecord::ACTIVE;
    $record->currency = 'XAF';
    $record->idShop = 1;
    foreach ($fields as $name => $value) {
        $record->{$name} = $value;
    }
    $GLOBALS['payments']->save($record);

    return $record;
}
