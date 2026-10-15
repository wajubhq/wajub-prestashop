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

use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\ApiReason;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\MoneyFormatter;
use Wajub\PrestaShop\Support\Text;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The order's payment paid in instalments, some collected and the plan
 * still open, before the payer pays the rest of the order
 * (Checkout\Starter::rest()): never two payments open at once, and never
 * what the plan collected asked again. A port of the WordPress plugin's
 * Gateway::decide() for a plan (resume(), closeHeld(), noteOpenPlan(),
 * noteStuck()).
 *
 * - An instalment with the payer right now (`processing`): waited for, on
 *   the order's confirmation page; one in progress for hours is pointed out
 *   to the merchant.
 * - In another currency than the order's: what it collected counts nothing
 *   toward the order, so it is neither taken up again nor closed for it;
 *   nothing new starts, and the merchant settles it.
 * - Taken up again on its own Wajub page, where the payer pays the next
 *   instalment, as long as completing it pays exactly what is left of the
 *   order and its session has time left.
 * - Otherwise closed, keeping what it collected, which then counts toward
 *   the order: the rest is asked for with a new payment. Wajub refusing the
 *   close as an instalment is in progress: waited for; as it is no longer
 *   between two instalments (closed or completed meanwhile), or not
 *   answering (it may have closed it all the same): read again, once.
 *
 * A payment that cannot be closed, nor read, stops the payer rather than
 * risk two payments open at once; the merchant reads why. The caller holds
 * the order's lock.
 */
final class Instalments
{
    /** Nothing open in instalments: the caller goes on. */
    public const NONE = 'none';

    /** Taken up again on its own page (`url`). */
    public const RESUME = 'resume';

    /** An instalment is with the payer: the order's confirmation page follows it. */
    public const WAIT = 'wait';

    /** Closed, what it collected paid the order. */
    public const PAID = 'paid';

    /** Closed, what it collected counted: the rest is asked for. */
    public const START = 'start';

    /** Neither taken up, closed nor read: nothing new starts. */
    public const STOP = 'stop';

    /** Seconds a payment's session must have left to be taken up again. */
    public const RESUME_MARGIN = 600;

    /** Seconds after which an instalment still in progress is pointed out to the merchant. */
    public const STUCK_AFTER = 21600;

    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var Settlement */
    private $settlement;

    /** @var Ledger */
    private $ledger;

    /** @var callable(string): Client */
    private $clientFor;

    /** @var Translator */
    private $t;

    /** @var MoneyFormatter */
    private $money;

    /** @var Logger */
    private $logger;

    /** @var string why the last close Wajub did not answer failed */
    private $closeFailure = '';

    /**
     * @param callable(string): Client $clientFor the shop's client of an environment, with its key only
     */
    public function __construct(ShopOrders $orders, PaymentStore $payments, Settlement $settlement, Ledger $ledger, callable $clientFor, Translator $t, MoneyFormatter $money, Logger $logger)
    {
        $this->orders = $orders;
        $this->payments = $payments;
        $this->settlement = $settlement;
        $this->ledger = $ledger;
        $this->clientFor = $clientFor;
        $this->t = $t;
        $this->money = $money;
        $this->logger = $logger;
    }

    /**
     * What the order's payment of the moment becomes before the rest is
     * asked for in `$environment`: `to` one of the constants, `url` for
     * RESUME. NONE for a payment that is not paid in instalments (still
     * open with nothing collected, ended, unreadable): the order's other
     * checks settle it (OpenPayments), with what Wajub said of it just now
     * (`read`, by payment id), not read a second time.
     *
     * @return array{to: string, url?: string, read?: array<string, array{outcome: string, status: string, transaction: array<string, mixed>, unreachable?: true, missing?: true}>}
     */
    public function settle(OrderView $order, string $environment): array
    {
        $record = $this->payments->current($order->id);
        if ($record === null || $record->state !== PaymentRecord::ACTIVE || $record->isCounted()) {
            return ['to' => self::NONE];
        }

        $read = $this->settlement->outcome($record, true);
        // Why it is read a second time: 'not_closed' (Wajub says it is no
        // longer between two instalments), or 'close_unanswered' (Wajub did
        // not answer its close).
        $again = '';
        // The payment as last read, for the merchant's note should a read
        // again not answer.
        $seen = $read['transaction'];
        while (true) {
            $transaction = $read['transaction'];
            if (Settlement::namesAnotherOrder($order, $transaction)) {
                return ['to' => self::NONE, 'read' => [$record->paymentId => $read]];
            }
            if ($transaction === []) {
                if ($again === 'close_unanswered') {
                    // Wajub answered neither the close nor a read since: it
                    // may be closed, or still open.
                    $this->noteOpenPlan($order, $seen, $this->closeFailure, true);
                }

                return $again !== '' ? ['to' => self::STOP] : ['to' => self::NONE, 'read' => [$record->paymentId => $read]];
            }

            $seen = $transaction;
            $status = strtolower($read['status']);
            if ($again !== '' && Ledger::endedWithMoney($status)) {
                // Closed or completed meanwhile: it counts, and pays the
                // order once its payments cover it.
                $applied = $this->settlement->apply($order, $record, ['outcome' => Client::OUTCOME_SUCCEEDED, 'status' => $status, 'transaction' => $transaction], Settlement::SEEN_BEFORE_NEW);
                $order = $this->orders->find($order->id) ?? $order;
                if ($order->paid) {
                    return ['to' => self::PAID];
                }

                return ['to' => $applied === Client::OUTCOME_PENDING ? self::STOP : self::START];
            }
            if (!Ledger::openWithMoney($transaction)) {
                return ['to' => $again !== '' ? self::START : self::NONE, 'read' => [$record->paymentId => $read]];
            }

            $this->settlement->remember($record, $transaction);
            // An instalment is with the payer: Wajub may still take it.
            if ($status === 'processing') {
                $this->noteStuck($order, $transaction);

                return ['to' => self::WAIT];
            }
            if (!Ledger::sameCurrency($order, $transaction)) {
                $this->noteOpenPlan($order, $transaction, null);

                return ['to' => self::STOP];
            }
            $resumed = $this->resume($order, $record, $transaction, $environment);
            if ($resumed !== null) {
                return $resumed;
            }
            // Read again after a close Wajub refused or did not answer: still
            // open, so it was not closed.
            if ($again !== '') {
                $this->noteOpenPlan($order, $transaction, $again === 'not_closed'
                    ? $this->t->trans('it was closed or completed meanwhile', [], 'Modules.Wajub.Admin')
                    : $this->closeFailure);

                return ['to' => self::STOP];
            }

            $closed = $this->close($order, $record, $transaction);
            if (is_array($closed)) {
                return $closed;
            }
            if ($closed === 'reread' || $closed === 'unanswered') {
                $again = $closed === 'reread' ? 'not_closed' : 'close_unanswered';
                $read = $this->settlement->outcome($record, true);

                continue;
            }

            return ['to' => self::STOP];
        }
    }

    /**
     * Takes the plan up again on its own Wajub page, as long as completing
     * it pays exactly what is left of the order and its session has time
     * left. Noted once per payment and what it collected. Null when it
     * cannot be.
     *
     * @param array<string, mixed> $transaction
     *
     * @return array{to: string, url: string}|null
     */
    private function resume(OrderView $order, PaymentRecord $record, array $transaction, string $environment): ?array
    {
        if (!is_numeric($transaction['amount'] ?? null) || $record->authorizationUrl() === '') {
            return null;
        }
        $left = $this->ledger->due($order, $environment);
        if ((int) round((float) $transaction['amount'] * 100) !== (int) round($left * 100)) {
            return null;
        }
        $expires = is_string($transaction['expires_at'] ?? null) && $transaction['expires_at'] !== '' ? strtotime($transaction['expires_at']) : false;
        if ($expires !== false && $expires - time() < self::RESUME_MARGIN) {
            return null;
        }

        $paid = Ledger::paidSoFar($transaction);
        $this->orders->note($order, 'resumed_' . md5($record->paymentId . '|' . $paid), $this->t->trans(
            'The customer went back to Wajub payment %payment% to pay its next instalments (%paid% of %amount% received so far).',
            [
                '%payment%' => $record->paymentId,
                '%paid%' => $this->money->format($paid, $order->currency),
                '%amount%' => $this->money->format((float) $transaction['amount'], $order->currency),
            ],
            'Modules.Wajub.Admin'
        ));

        return ['to' => self::RESUME, 'url' => $record->authorizationUrl()];
    }

    /**
     * Closes the plan, keeping what it collected, which then counts toward
     * the order. The answer once it is closed (the order paid, the rest to
     * ask for, an instalment to wait for), 'reread' when Wajub says it is no
     * longer between two instalments, 'unanswered' when Wajub did not answer
     * (it may have closed it all the same), or 'failed'.
     *
     * @param array<string, mixed> $transaction
     *
     * @return array{to: string}|string
     */
    private function close(OrderView $order, PaymentRecord $record, array $transaction)
    {
        try {
            $answer = Client::extractTransaction(($this->clientFor)($record->environment)->closePayment($record->paymentId, 'keep'));
        } catch (ApiException $e) {
            $code = strtolower($e->errorCode);
            if ($e->httpStatus === 409 && $code === 'payment_in_progress') {
                return ['to' => self::WAIT];
            }
            if ($e->httpStatus === 409 && in_array($code, ['payment_not_partial', 'payment_already_closed'], true)) {
                return 'reread';
            }
            if (Client::unanswered($e)) {
                $this->closeFailure = ApiReason::of($e, $this->t);

                return 'unanswered';
            }
            $this->noteOpenPlan($order, $transaction, ApiReason::of($e, $this->t));

            return 'failed';
        } catch (\Throwable $e) {
            $this->closeFailure = ApiReason::of($e, $this->t);

            return 'unanswered';
        }

        $this->settlement->forgetOutcome($record);
        $closed = $answer !== [] && Text::code($answer['id'] ?? '') === $record->paymentId ? $answer : ['status' => 'succeeded'] + $transaction;
        $this->logger->info('Payment paid in instalments closed, keeping what it collected', ['order' => $order->id, 'payment' => $record->paymentId]);
        $this->orders->note($order, 'closed_' . md5($record->paymentId), $this->t->trans(
            'Wajub payment %payment%, paid in instalments, could no longer be completed: it was closed, keeping the %collected% its instalments collected; the customer pays the rest with a new payment.',
            ['%payment%' => $record->paymentId, '%collected%' => $this->money->format(Amount::collected($closed), self::currencyOf($closed, $order))],
            'Modules.Wajub.Admin'
        ));

        $applied = $this->settlement->apply($order, $record, ['outcome' => Client::OUTCOME_SUCCEEDED, 'status' => strtolower((string) ($closed['status'] ?? 'succeeded')), 'transaction' => $closed], Settlement::SEEN_CLOSED);
        $order = $this->orders->find($order->id) ?? $order;
        if ($order->paid) {
            return ['to' => self::PAID];
        }

        return $applied === Client::OUTCOME_PENDING ? 'failed' : ['to' => self::START];
    }

    /**
     * Tells the merchant, once per payment and what it collected, that a
     * payment paid in instalments is left open, so nothing new started: it
     * could not be closed (`$reason`), or (null) it is in another currency
     * than the order's, and counts nothing toward it. `$unanswered`: Wajub
     * answered neither its close nor a read of it since, so it may have
     * been closed all the same.
     *
     * @param array<string, mixed> $transaction
     */
    private function noteOpenPlan(OrderView $order, array $transaction, ?string $reason, bool $unanswered = false): void
    {
        $paymentId = Ledger::idOf($transaction);
        $paid = Ledger::paidSoFar($transaction);
        $collected = $this->money->format($paid, self::currencyOf($transaction, $order));
        $key = 'not_closed_' . md5($paymentId . '|' . $paid);
        if ($unanswered) {
            $this->orders->note($order, $key, $this->t->trans(
                'Wajub did not answer when this shop closed Wajub payment %payment%, paid in instalments, which collected %collected% (%reason%), nor when it read the payment again, so no new payment was started. Check the payment in the Wajub Dashboard, and close it there, keeping what it collected, if it is still open: the customer can then pay the rest.',
                ['%payment%' => $paymentId, '%collected%' => $collected, '%reason%' => $reason !== null && $reason !== '' ? $reason : $this->t->trans('no reason given', [], 'Modules.Wajub.Admin')],
                'Modules.Wajub.Admin'
            ));

            return;
        }
        $this->orders->note($order, $key, $reason === null
            ? $this->t->trans(
                'Wajub payment %payment%, paid in instalments, collected %collected%, in another currency than the order\'s, so no new payment was started. Close it in the Wajub Dashboard, refunding what it collected if the customer should get it back: the customer can then pay the order.',
                ['%payment%' => $paymentId, '%collected%' => $collected],
                'Modules.Wajub.Admin'
            )
            : $this->t->trans(
                'Wajub payment %payment%, paid in instalments, collected %collected% but could not be closed (%reason%), so no new payment was started. Close it in the Wajub Dashboard, keeping what it collected: the customer can then pay the rest.',
                ['%payment%' => $paymentId, '%collected%' => $collected, '%reason%' => $reason],
                'Modules.Wajub.Admin'
            ));
    }

    /**
     * Tells the merchant, once per payment and change, that an instalment
     * has been in progress for hours: the payer cannot pay the rest of the
     * order until Wajub settles it.
     *
     * @param array<string, mixed> $transaction
     */
    private function noteStuck(OrderView $order, array $transaction): void
    {
        $updated = is_string($transaction['updated_at'] ?? null) ? strtotime($transaction['updated_at']) : false;
        if ($updated === false || time() - $updated < self::STUCK_AFTER) {
            return;
        }
        $paymentId = Ledger::idOf($transaction);
        $this->orders->note($order, 'stuck_' . md5($paymentId . '|' . $updated), $this->t->trans(
            'An instalment of Wajub payment %payment% has been in progress since %date%: the customer cannot pay the rest of the order until Wajub settles it. Check the payment in the Wajub Dashboard.',
            ['%payment%' => $paymentId, '%date%' => date('Y-m-d H:i', $updated)],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * The currency a payment's amounts are in, to show them: its own, else
     * the order's.
     *
     * @param array<string, mixed> $payment
     */
    private static function currencyOf(array $payment, OrderView $order): string
    {
        $currency = strtoupper(Text::code($payment['currency'] ?? '', 3));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : $order->currency;
    }
}
