<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Order;

use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Payment\RefundRecord;
use Wajub\PrestaShop\Payment\RefundStore;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Wajub payments that count toward an order, and what they still hold
 * for it, read from the shop's own records without asking Wajub (Settlement
 * does that). A port of the WordPress plugin's OrderPayments.
 *
 * A payment counts once, for what it collected (Support\Amount::collected():
 * never more than it asked), in the order's currency, with the PrestaShop
 * payment (OrderPayment) that says it on the order. An order is paid once
 * what its payments hold (what they collected, less their refunds), in one
 * environment, covers its total: one payment in full, or one short of it
 * (an instalment payment closed early, a total raised since) and the rest
 * paid next. A payment in another currency never counts, nor does a
 * Sandbox payment toward a Live order's total with Live ones.
 */
final class Ledger
{
    /** The statuses of a payment that ended having collected money. */
    public const ENDED_WITH_MONEY = ['succeeded', 'complete', 'paid', 'partially_refunded', 'partially-refunded', 'refunded'];

    /** @var PaymentStore */
    private $payments;

    /** @var RefundStore */
    private $refunds;

    public function __construct(PaymentStore $payments, RefundStore $refunds)
    {
        $this->payments = $payments;
        $this->refunds = $refunds;
    }

    /**
     * What each payment that counts toward the order in `$environment`
     * collected, oldest first: those of that environment, in the order's
     * currency.
     *
     * @return array<string, float> by Wajub payment id
     */
    public function counted(OrderView $order, string $environment): array
    {
        $counted = [];
        foreach ($this->payments->forOrder($order->id) as $record) {
            if ($record->isCounted() && $record->environment === $environment && $record->currency === $order->currency) {
                $counted[$record->paymentId] = $record->counted;
            }
        }

        return $counted;
    }

    /**
     * Every payment the order counts, whatever its environment and currency,
     * oldest first.
     *
     * @return list<PaymentRecord>
     */
    public function entries(OrderView $order): array
    {
        return array_values(array_filter($this->payments->forOrder($order->id), static function (PaymentRecord $record): bool {
            return $record->isCounted();
        }));
    }

    /**
     * 'sandbox' or 'live': the environment the order was paid in, as far as
     * what it counts says: the one whose payments collected its total (a
     * payment of the other one, short of it, paid none of it), else that of
     * the payment it holds now. '' when nothing says.
     */
    public function paidIn(OrderView $order): string
    {
        $collected = ['sandbox' => 0.0, 'live' => 0.0];
        foreach ($this->entries($order) as $record) {
            if ($record->currency === $order->currency && isset($collected[$record->environment])) {
                $collected[$record->environment] += $record->counted;
            }
        }
        $total = self::total($order);
        $covering = array_keys(array_filter($collected, static function (float $sum) use ($total): bool {
            return $sum > 0 && $sum + 0.000001 >= $total;
        }));
        if (count($covering) === 1) {
            return (string) $covering[0];
        }
        $current = $this->payments->current($order->id);

        return $current !== null && in_array($current->environment, ['sandbox', 'live'], true) ? $current->environment : '';
    }

    /** What the refunds of one payment take back, or will, in the order's currency. */
    public function refunded(OrderView $order, string $paymentId): float
    {
        return $this->refunds->refunded($paymentId, $order->currency);
    }

    /**
     * How a refund of `$amount` is split across the order's payments in
     * `$environment`: the newest first, each up to what it can still refund.
     * Null when they cannot refund that much between them.
     *
     * `$again`: the shop's own refunds asked before that no credit slip
     * records yet (Refund\RefundSync), which a part may be: the same payment,
     * for the amount the part came to when that refund was asked. Wajub
     * answers such a part with the refund it made, never a second one, so the
     * part takes that refund's key, and is planned from the room its payment
     * had then: once Wajub said the refund exists (pending, under way), the
     * ledger takes it off already. Any other part is a refund of its own,
     * from what its payment can still refund.
     *
     * @param list<RefundRecord> $again
     *
     * @return list<array{0: string, 1: float, 2: string}>|null [payment id, amount, key of the refund asked again or ''], in the order they are refunded
     */
    public function refundPlan(OrderView $order, float $amount, string $environment, array $again = []): ?array
    {
        $left = Amount::normalize($amount, $order->currency);
        if ($left <= 0) {
            return null;
        }

        $plan = [];
        foreach (array_reverse($this->counted($order, $environment), true) as $paymentId => $collected) {
            $room = Amount::normalize($collected - $this->refunds->refunded((string) $paymentId, $order->currency), $order->currency);
            $part = 0.0;
            $key = '';
            foreach ($again as $i => $refund) {
                if ($refund->paymentId !== (string) $paymentId || $refund->requestKey === '') {
                    continue;
                }
                $then = min(Amount::normalize($room + ($refund->takesMoney() ? $refund->amount : 0.0), $order->currency), $left);
                if (abs($then - $refund->amount) < 0.000001) {
                    $part = $refund->amount;
                    $key = $refund->requestKey;
                    // Two parts never share one refund.
                    unset($again[$i]);
                    break;
                }
            }
            if ($key === '') {
                if ($room <= 0) {
                    continue;
                }
                $part = min($room, $left);
            }
            $plan[] = [(string) $paymentId, $part, $key];
            $left = Amount::normalize($left - $part, $order->currency);
            if ($left <= 0.000001) {
                return $plan;
            }
        }

        return null;
    }

    public function isCounted(OrderView $order, string $paymentId): bool
    {
        $record = $paymentId !== '' ? $this->payments->find($paymentId) : null;

        return $record !== null && $record->idOrder === $order->id && $record->isCounted();
    }

    /** What the payments that count in `$environment` hold for the order: what they collected, less their refunds. */
    public function held(OrderView $order, string $environment): float
    {
        $held = 0.0;
        foreach ($this->counted($order, $environment) as $paymentId => $collected) {
            $held += max(0.0, $collected - $this->refunds->refunded((string) $paymentId, $order->currency));
        }

        return Amount::normalize($held, $order->currency);
    }

    /**
     * The order's total as Wajub counts it, which is what a payment of the
     * whole order asks: PrestaShop may keep more decimals than the currency
     * has at Wajub (XAF set to two decimals in the shop, a currency of three
     * such as TND), and a payment of exactly what was asked must cover it.
     */
    public static function total(OrderView $order): float
    {
        return Amount::normalize($order->total, $order->currency);
    }

    /** What is left to pay of the order's total in `$environment`. */
    public function due(OrderView $order, string $environment): float
    {
        return max(0.0, Amount::normalize(self::total($order) - $this->held($order, $environment), $order->currency));
    }

    /** Whether what the payments hold in `$environment` covers the order's total. */
    public function covers(OrderView $order, string $environment): bool
    {
        $held = $this->held($order, $environment);

        return $held > 0 && $held + 0.000001 >= self::total($order);
    }

    /**
     * Whether counting this payment too would cover the order in
     * `$environment`, without writing anything.
     *
     * @param array<string, mixed> $payment as GET /payments/{id} or its webhook gives it
     */
    public function coversWith(OrderView $order, array $payment, string $environment): bool
    {
        $paymentId = self::idOf($payment);
        if ($paymentId === '' || !self::sameCurrency($order, $payment)) {
            return false;
        }
        if ($this->isCounted($order, $paymentId)) {
            return $this->covers($order, $environment);
        }

        $own = max(0.0, Amount::normalize(Amount::collected($payment), $order->currency) - $this->refunds->refunded($paymentId, $order->currency));
        $held = Amount::normalize($this->held($order, $environment) + $own, $order->currency);

        return $held > 0 && $held + 0.000001 >= self::total($order);
    }

    /**
     * Whether the order holds money another payment must not charge again:
     * what its counted payments hold, in either environment, or a payment
     * being paid in instalments with some collected.
     */
    public function holdsMoney(OrderView $order): bool
    {
        if ($this->held($order, 'live') > 0 || $this->held($order, 'sandbox') > 0) {
            return true;
        }

        return $this->instalmentsOpen($order) !== null;
    }

    /**
     * The order's payment of the moment while it is paid in instalments,
     * some collected and the plan still open, as last read; null otherwise.
     */
    public function instalmentsOpen(OrderView $order): ?PaymentRecord
    {
        $current = $this->payments->current($order->id);

        return $current !== null && $current->state === PaymentRecord::ACTIVE && !$current->isCounted()
            && self::openWithMoneyStatus($current->status, $current->amountPaid) ? $current : null;
    }

    /**
     * The order's payments that went through but could not count yet, their
     * refunds unread: the order is never cancelled while there is one.
     *
     * @return list<PaymentRecord>
     */
    public function uncounted(OrderView $order): array
    {
        return array_values(array_filter($this->payments->forOrder($order->id), static function (PaymentRecord $record): bool {
            return $record->state === PaymentRecord::UNCOUNTED && !$record->isCounted();
        }));
    }

    /**
     * The order's payments that went through without counting toward it (in
     * another currency than the order's, which the merchant is told to
     * refund), as last read, unless refunded in full since: the payer was
     * charged, and the order is never cancelled as unpaid while there is one.
     *
     * @return list<PaymentRecord>
     */
    public function chargedUncounted(OrderView $order): array
    {
        return array_values(array_filter($this->payments->forOrder($order->id), static function (PaymentRecord $record): bool {
            return !$record->isCounted() && $record->state === PaymentRecord::FINAL
                && self::endedWithMoney($record->status) && strtolower($record->status) !== 'refunded';
        }));
    }

    /**
     * Whether the payment is in the order's currency.
     *
     * @param array<string, mixed> $payment
     */
    public static function sameCurrency(OrderView $order, array $payment): bool
    {
        $currency = strtoupper(Text::code($payment['currency'] ?? '', 3));

        return $currency !== '' && $currency === $order->currency;
    }

    /**
     * Whether the payment is paid in instalments, some collected, and still
     * open: between two instalments (`partial`), or while one is with the
     * payer (`processing` with something collected already).
     *
     * @param array<string, mixed> $payment
     */
    public static function openWithMoney(array $payment): bool
    {
        return self::openWithMoneyStatus(strtolower(Text::code($payment['status'] ?? '', 32)), self::paidSoFar($payment));
    }

    private static function openWithMoneyStatus(string $status, float $paidSoFar): bool
    {
        return $status === 'partial' || (in_array($status, ['pending', 'processing'], true) && $paidSoFar > 0);
    }

    /** Whether a payment in this status ended having collected money, refunds or not. */
    public static function endedWithMoney(string $status): bool
    {
        return in_array(strtolower($status), self::ENDED_WITH_MONEY, true);
    }

    /**
     * What an open payment collected so far: its `amount_paid`, never more
     * than its amount; 0 while it says nothing of it.
     *
     * @param array<string, mixed> $payment
     */
    public static function paidSoFar(array $payment): float
    {
        $paid = is_numeric($payment['amount_paid'] ?? null) ? (float) $payment['amount_paid'] : 0.0;
        if ($paid <= 0) {
            return 0.0;
        }

        return is_numeric($payment['amount'] ?? null) ? min($paid, (float) $payment['amount']) : $paid;
    }

    /**
     * 'sandbox' or 'live': the environment the payment says it was made in
     * (`sandbox`), else `$fallback` (the key that read it, or the secret that
     * signed its delivery).
     *
     * @param array<string, mixed> $payment
     */
    public static function environmentOf(array $payment, string $fallback): string
    {
        if (array_key_exists('sandbox', $payment) && $payment['sandbox'] !== null) {
            return filter_var($payment['sandbox'], FILTER_VALIDATE_BOOLEAN) ? 'sandbox' : 'live';
        }

        return $fallback === 'sandbox' ? 'sandbox' : 'live';
    }

    /**
     * The payment's Wajub id, or '' for a payment without one.
     *
     * @param array<string, mixed> $payment
     */
    public static function idOf(array $payment): string
    {
        $id = Text::code($payment['id'] ?? '', 80);

        return preg_match('/^(trx_|pay_)[A-Za-z0-9_]{1,64}$/', $id) === 1 ? $id : '';
    }
}
