<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Refund;

use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\OrderView;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Order\ShopOrders;
use Wajub\PrestaShop\Payment\DbRefundStore;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Payment\RefundRecord;
use Wajub\PrestaShop\Payment\RefundStore;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\ApiReason;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\MoneyFormatter;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Refunds, both ways. A port of the WordPress plugin's
 * Gateway::process_refund(), RefundSync and the WooCommerce part of
 * Webhooks\Handler::handleRefundEvent().
 *
 * From the back office: a credit slip the merchant makes (Partial refund,
 * Standard refund, Return products, without a voucher) is refunded through
 * Wajub before PrestaShop makes it (refundForSlip()), split across the
 * payments that paid the order, the newest first, each up to what it can
 * still refund. Refused, nothing is refunded and PrestaShop makes no slip;
 * refused after a part went through, what Wajub refunded is recorded on a
 * slip of its own (recordParts()), and the merchant reads the rest. Each
 * refund carries a key of its own, kept before the call, which Wajub keeps
 * on the refund (the call sent again makes no second one) and its webhooks
 * carry: a webhook that arrives before the call returns finds it. One Wajub
 * did not answer is asked again under the same key when the merchant asks
 * for the same part again, until a slip records it (its webhooks may have
 * said meanwhile that Wajub made it): Wajub answers with the refund it made,
 * never a second one. One that went through without PrestaShop making its
 * slip (its call unanswered, or PrestaShop refusing the slip afterwards) is
 * recorded on the order once its webhook says it succeeded, as one made in
 * the Wajub Dashboard is, or at the end of the request (recordWithoutSlip(),
 * or recordOnUnseenSlip() when PrestaShop made the slip out of the module's
 * sight).
 *
 * Only in the environment Wajub uses now, for an order paid in it: a
 * refund started in the other environment is refused, as is one of an
 * order never paid in full (its money is given back from the Wajub
 * Dashboard, and asked again from the payer).
 *
 * From the Wajub Dashboard: a refund's webhooks (webhook()) note it on the
 * payment that made it, each status it goes through, pending ones included:
 * what an order holds is what its payments collected, less their refunds.
 * One of a payment that paid the order, once it succeeded, is recorded on
 * the order as a credit slip, but for what it gave back of money Wajub held
 * beyond the order's total, or of money the order records as refunded
 * already without Wajub; the order is "Refunded" once Wajub has given back
 * all its payments collected. One first seen while the order was unpaid
 * makes the payer pay it again: never a slip. One of a payment that did not
 * pay the order (a second payment, one in the other environment, one of an
 * order never paid) is only noted.
 *
 * Every caller holds the order's lock.
 */
final class RefundSync
{
    public const NONE = 'none';

    public const DONE = 'done';

    public const REFUSED = 'refused';

    public const PARTIAL = 'partial';

    /** @var ShopOrders */
    private $orders;

    /** @var PaymentStore */
    private $payments;

    /** @var RefundStore */
    private $refunds;

    /** @var Ledger */
    private $ledger;

    /** @var Settlement */
    private $settlement;

    /** @var callable(string): Client */
    private $clientFor;

    /** @var Environment */
    private $environment;

    /** @var Translator */
    private $t;

    /** @var MoneyFormatter */
    private $money;

    /** @var Logger */
    private $logger;

    /**
     * The keys of the refunds this request is asking Wajub for, while each
     * call lasts: a webhook of one delivered meanwhile finds its row with no
     * slip yet, and must not record it as one PrestaShop made no slip for.
     *
     * @var array<string, true>
     */
    private $asking = [];

    /**
     * @param callable(string): Client $clientFor the shop's client of an environment, with its key only
     */
    public function __construct(ShopOrders $orders, PaymentStore $payments, RefundStore $refunds, Ledger $ledger, Settlement $settlement, callable $clientFor, Environment $environment, Translator $t, MoneyFormatter $money, Logger $logger)
    {
        $this->orders = $orders;
        $this->payments = $payments;
        $this->refunds = $refunds;
        $this->ledger = $ledger;
        $this->settlement = $settlement;
        $this->clientFor = $clientFor;
        $this->environment = $environment;
        $this->t = $t;
        $this->money = $money;
        $this->logger = $logger;
    }

    /**
     * Refunds `$amount` of the order through Wajub, for the credit slip the
     * merchant is making. `status`:
     * - NONE: no Wajub payment counts toward the order (paid another way):
     *   nothing for Wajub to refund, the slip is made without it;
     * - DONE: Wajub refunded it all, under `keys` (none for an amount of 0 or
     *   less: nothing to give back);
     * - REFUSED: nothing refunded, `message` says why: no slip;
     * - PARTIAL: Wajub refunded `refunded` of it, under `keys`, then refused
     *   the rest: `message` says what went through and why the rest did not.
     *
     * @return array{status: string, message: string, keys: list<string>, refunded: float}
     */
    public function refundForSlip(OrderView $order, float $amount, string $reason = ''): array
    {
        // PrestaShop computes nothing to give back (products returned for an
        // exchange, the order's voucher taken off their price): nothing for
        // Wajub, and PrestaShop says itself what it makes of it.
        if (Amount::normalize($amount, $order->currency) <= 0) {
            return self::answer(self::DONE);
        }
        if ($this->ledger->entries($order) === []) {
            $this->orders->note($order, 'slip_without_wajub_' . md5((string) $this->orders->lastSlip($order) . '|' . $amount), $this->t->trans(
                'No Wajub payment counts toward this order: the refund of %amount% was recorded without Wajub. Refund the customer another way.',
                ['%amount%' => $this->money->format($amount, $order->currency)],
                'Modules.Wajub.Admin'
            ));

            return self::answer(self::NONE);
        }
        if (!$order->paidOnce) {
            return self::answer(self::REFUSED, $this->t->trans('This order is not paid in full: refund its Wajub payments from the Wajub Dashboard. The customer is then asked for what was refunded.', [], 'Modules.Wajub.Admin'));
        }

        $environment = $this->environment->isLive() ? 'live' : 'sandbox';
        $paidIn = $this->ledger->paidIn($order);
        // Paid in the other environment: refused even when the order also
        // counts a payment of this one (4 000 in Sandbox, the rest in Live),
        // whose refund gives back none of the money that paid it.
        if ($this->ledger->counted($order, $environment) === [] || ($paidIn !== '' && $paidIn !== $environment)) {
            if ($paidIn === '' || $paidIn === $environment) {
                $entries = $this->ledger->entries($order);
                $paidIn = $entries[0]->environment;
            }

            return self::answer(self::REFUSED, $paidIn === 'live'
                ? $this->t->trans('This order was paid in Wajub Live: refund it while Wajub uses that environment, or from the Wajub Dashboard.', [], 'Modules.Wajub.Admin')
                : $this->t->trans('This order was paid in Wajub Sandbox: refund it while Wajub uses that environment, or from the Wajub Dashboard.', [], 'Modules.Wajub.Admin'));
        }

        $asked = Amount::normalize($amount, $order->currency);
        // The same part asked before, which no slip records yet (its call
        // went unanswered, its webhook may have said Wajub made it since):
        // asked again under its key, which Wajub answers with the refund it
        // made, if it made it, rather than refunding the customer twice.
        $plan = $this->ledger->refundPlan($order, $asked, $environment, $this->unrecorded($order, $environment));
        if ($plan === null) {
            return self::answer(self::REFUSED, $this->t->trans('Wajub can refund at most %amount% on this order.', ['%amount%' => $this->money->format($this->ledger->held($order, $environment), $order->currency)], 'Modules.Wajub.Admin'));
        }

        $done = [];
        $failure = null;
        foreach ($plan as [$paymentId, $part, $key]) {
            $record = $this->payments->find($paymentId);
            if ($record === null) {
                break;
            }
            if ($key === '') {
                $key = bin2hex(random_bytes(16));
                $this->refunds->requested($record, $key, $part, $reason);
            }
            $this->asking[$key] = true;
            try {
                $response = ($this->clientFor)($environment)->createRefund($paymentId, $part, $reason !== '' ? $reason : null, $order->currency, [
                    'source' => 'prestashop',
                    'order_id' => (string) $order->id,
                    'request_key' => $key,
                ], 'wajub-prestashop-refund-' . $key);
            } catch (\Throwable $e) {
                unset($this->asking[$key]);
                // Not answered, though its webhook, delivered while the call
                // was open, said Wajub made it: it went through.
                $known = Client::unanswered($e) ? $this->refunds->findByKey($key) : null;
                if ($known !== null && $known->refundId !== '' && $known->takesMoney()) {
                    $done[] = ['key' => $key, 'payment' => $paymentId, 'amount' => $part];
                    $this->logger->info('Refund made, its call unanswered: known by its webhook', ['order' => $order->id, 'payment' => $paymentId, 'amount' => $part]);

                    continue;
                }
                // Refused: no money moved. Not answered: Wajub may have made
                // it, and its webhook, which carries the key, says so.
                if (!Client::unanswered($e)) {
                    $this->refunds->forget($key);
                }
                $failure = $e;
                $this->logger->error('Refund not made', ['order' => $order->id, 'payment' => $paymentId, 'error' => $e->getMessage()]);
                break;
            }
            unset($this->asking[$key]);
            $refund = is_array($response['refund'] ?? null) ? $response['refund'] : $response;
            $this->refunds->answered($key, $refund);
            $done[] = ['key' => $key, 'payment' => $paymentId, 'amount' => $part];
            $this->logger->info('Refund made', ['order' => $order->id, 'payment' => $paymentId, 'amount' => $part]);
        }

        $made = implode(', ', array_map(function (array $part) use ($order): string {
            return $this->t->trans('%amount% from %payment%', ['%amount%' => $this->money->format($part['amount'], $order->currency), '%payment%' => $part['payment']], 'Modules.Wajub.Admin');
        }, $done));
        $keys = array_map(static function (array $part): string {
            return $part['key'];
        }, $done);
        if ($failure === null) {
            $this->orders->note($order, 'refunded_' . md5(implode('|', $keys)), $this->t->trans('Wajub refunded %refunds%.', ['%refunds%' => $made], 'Modules.Wajub.Admin'));

            return self::answer(self::DONE, '', $keys, $asked);
        }

        $reason = ApiReason::of($failure, $this->t);
        if ($done === []) {
            if (Client::unanswered($failure)) {
                return self::answer(self::REFUSED, $this->t->trans('Wajub did not answer (%reason%): the refund may have been made all the same, and is then recorded on the order as a credit slip once Wajub confirms it. Until then, asking again for the same amount sends the same refund again: the customer is not refunded twice.', ['%reason%' => $reason], 'Modules.Wajub.Admin'));
            }
            // Refused as more than the payment can refund: Wajub may have
            // refunded some of it already, out of the shop's sight (a
            // refund made in the Wajub Dashboard whose webhook never
            // arrived). Its refunds are read, and recorded as their webhooks
            // would have.
            $caught = self::refusedAsAsked($failure) ? $this->catchUp($order, $environment) : 0.0;

            return self::answer(self::REFUSED, $caught > 0
                ? $this->t->trans('Wajub did not refund it: %reason%. Wajub had refunded %amount% of this order that the shop had not recorded (its notification never arrived): it is recorded on the order now, as a credit slip. Check what is left to refund.', ['%reason%' => rtrim($reason, '.'), '%amount%' => $this->money->format($caught, $order->currency)], 'Modules.Wajub.Admin')
                : $this->t->trans('Wajub did not refund it: %reason%', ['%reason%' => $reason], 'Modules.Wajub.Admin'));
        }

        $refunded = Amount::normalize(array_sum(array_column($done, 'amount')), $order->currency);

        return self::answer(self::PARTIAL, $this->t->trans('Wajub refunded %refunds% of the %asked% asked, then refused the rest: %reason%. What Wajub refunded is recorded on the order, as a credit slip of its own.', [
            '%refunds%' => $made,
            '%asked%' => $this->money->format($asked, $order->currency),
            '%reason%' => $reason,
        ], 'Modules.Wajub.Admin'), $keys, $refunded);
    }

    /**
     * Whether Wajub refused the refund as asked (more than the payment can
     * still refund, a payment refunded in full already), rather than the key
     * or the payment: a 400, 409 or 422.
     */
    private static function refusedAsAsked(\Throwable $failure): bool
    {
        return $failure instanceof ApiException && in_array($failure->httpStatus, [400, 409, 422], true);
    }

    /**
     * The refunds of the order's payments counted in `$environment` that
     * Wajub made and the shop never heard of, or heard less of (their
     * webhooks lost: an endpoint down past Wajub's retries, a `wajub
     * listen` stopped), read from Wajub and recorded as their webhooks would
     * have been (webhook()): one that gave back money of the order on a
     * credit slip of its own, the others noted. Each is known by its id once
     * recorded, so a webhook that arrives after all records nothing twice.
     * What the order's credit slips refund more than before.
     *
     * The caller holds the order's lock, and has no credit slip of
     * PrestaShop's coming (the refund was refused): a slip made here is
     * never taken for the form's (Refund\PendingSlips).
     */
    public function catchUp(OrderView $order, string $environment): float
    {
        $before = $this->orders->refundedOnOrder($order);
        foreach (array_keys($this->ledger->counted($order, $environment)) as $paymentId) {
            $record = $this->payments->find((string) $paymentId);
            if ($record === null || $record->environment !== $environment) {
                continue;
            }
            try {
                $refunds = ($this->clientFor)($environment)->paymentRefunds($record->paymentId);
            } catch (\Throwable $e) {
                $this->logger->info('Refunds of a payment could not be read after a refused refund', ['order' => $order->id, 'payment' => $record->paymentId, 'error' => $e->getMessage()]);

                continue;
            }
            foreach ($refunds as $refund) {
                $refundId = DbRefundStore::refundId($refund['id'] ?? '');
                $known = $refundId !== '' ? $this->refunds->find($refundId) : null;
                if ($refundId === '' || ($known !== null && !DbRefundStore::movesOn($known->status, DbRefundStore::status($refund['status'] ?? '')))) {
                    continue;
                }
                $this->logger->info('Refund the shop had not heard of, read after a refused refund', ['order' => $order->id, 'payment' => $record->paymentId, 'refund' => $refundId]);
                $this->webhook($this->orders->find($order->id) ?? $order, $record, 'refund.updated', $refund);
            }
        }
        $order = $this->orders->find($order->id) ?? $order;

        return max(0.0, Amount::normalize($this->orders->refundedOnOrder($order) - $before, $order->currency));
    }

    /**
     * Records on the order the parts of a refund Wajub made before it
     * refused the next one (refundForSlip()), once PrestaShop made no slip of
     * the whole: one slip of what Wajub refunded, with a note per part. Each
     * part's webhook is known by its key, and records nothing more. The
     * slip's id.
     *
     * @param list<string> $keys
     */
    public function recordParts(OrderView $order, array $keys): int
    {
        return $this->slipOf($order, $keys, function (RefundRecord $refund) use ($order): string {
            return $this->t->trans(
                'Wajub refunded %amount% from payment %payment% (refund %refund%). The rest of the refund asked did not go through.',
                ['%amount%' => $this->money->format($refund->amount, $order->currency), '%payment%' => $refund->paymentId, '%refund%' => $refund->refundId !== '' ? $refund->refundId : '—'],
                'Modules.Wajub.Admin'
            );
        });
    }

    /**
     * Records on the order the refunds refundForSlip() made for a credit
     * slip PrestaShop then did not make (its command refused once Wajub had
     * refunded, or failed): the money is back with the customer, so one slip
     * of what Wajub refunded, with a note per refund. Each refund's webhook
     * finds it recorded. The slip's id, 0 when there was nothing left to
     * record.
     *
     * @param list<string> $keys
     */
    public function recordWithoutSlip(OrderView $order, array $keys): int
    {
        return $this->slipOf($order, $keys, function (RefundRecord $refund) use ($order): string {
            return $this->t->trans(
                'Wajub refunded %amount% from payment %payment% (refund %refund%), but PrestaShop did not make the credit slip of this refund: what Wajub refunded is recorded on a credit slip of its own.',
                ['%amount%' => $this->money->format($refund->amount, $order->currency), '%payment%' => $refund->paymentId, '%refund%' => $refund->refundId !== '' ? $refund->refundId : '—'],
                'Modules.Wajub.Admin'
            );
        });
    }

    /**
     * A credit slip PrestaShop made of a refund of the order page that Wajub,
     * or the module, refused this request (Refund\RefusedRefund), which it
     * should not have made: never refunded through Wajub (part of it may be
     * on a slip of the module's already), and the merchant is told what to
     * do. Why it was refused goes to the log, not in the note: it is the
     * message PrestaShop should have shown, written for a refund that makes
     * no slip ("no credit slip was made", "try again"). PrestaShop deletes no
     * credit slip (its back office only lists them and prints them): the
     * merchant refunds the customer another way, or corrects the stock and
     * the accounts the slip changed.
     */
    public function noteRefusedSlip(OrderView $order, int $idOrderSlip, string $reason): void
    {
        $this->logger->error('PrestaShop made the credit slip of a refund Wajub refused: not refunded through Wajub', ['order' => $order->id, 'slip' => $idOrderSlip, 'reason' => $reason]);
        $this->orders->note($order, 'refused_slip_' . $idOrderSlip, $this->t->trans(
            'PrestaShop made credit slip %slip% though Wajub had refused this refund: Wajub refunded nothing for it (Advanced Parameters > Logs says why). If the customer should get this money back, refund them another way; otherwise, correct the stock and your accounts, as PrestaShop cannot delete a credit slip.',
            ['%slip%' => (string) $idOrderSlip],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * Records the refunds refundForSlip() made on the credit slip PrestaShop
     * made of them, which its hook (actionOrderSlipAdd) has not brought to
     * the module (Refund\PendingSlips): PrestaShop adds the slip, then runs
     * the hook, which stops at the first module whose hook fails (a PHP
     * error, or any exception in debug mode). PrestaShop then reports the
     * error, before writing the quantities the slip refunded, and the
     * merchant is told to check them before refunding the order again;
     * until the hook reaches the module after all (slipSeen()).
     *
     * @param list<string> $keys
     */
    public function recordOnUnseenSlip(OrderView $order, array $keys, int $idOrderSlip): void
    {
        $amount = 0.0;
        foreach ($keys as $key) {
            $refund = $this->refunds->findByKey($key);
            if ($refund !== null && $refund->idOrderSlip === 0) {
                $this->refunds->linkSlip($refund, $idOrderSlip);
                $amount += $refund->amount;
            }
        }
        if ($amount <= 0) {
            return;
        }
        $this->orders->note($order, self::unseenSlipNote($idOrderSlip), $this->t->trans(
            'PrestaShop made credit slip %slip% of this refund without the module being told: it records the %amount% Wajub refunded for it. An error may have stopped the refund after the slip: check the refunded quantities of the order\'s products before refunding it again.',
            ['%slip%' => (string) $idOrderSlip, '%amount%' => $this->money->format(Amount::normalize($amount, $order->currency), $order->currency)],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * PrestaShop's hook told the module of the credit slip
     * recordOnUnseenSlip() recorded the refunds on as PrestaShop added it
     * (Refund\PendingSlips): PrestaShop went on with the refund, and the note
     * that it may have stopped after the slip is taken back.
     */
    public function slipSeen(OrderView $order, int $idOrderSlip): void
    {
        $this->orders->forgetNote($order, self::unseenSlipNote($idOrderSlip));
    }

    /** The key of the note that PrestaShop may have stopped after the slip. */
    private static function unseenSlipNote(int $idOrderSlip): string
    {
        return 'unseen_slip_' . $idOrderSlip;
    }

    /**
     * One credit slip of the shop's own refunds under `$keys` that no slip
     * records yet, with a note per refund (`$note`).
     *
     * @param list<string> $keys
     * @param callable(RefundRecord): string $note
     */
    private function slipOf(OrderView $order, array $keys, callable $note): int
    {
        $parts = [];
        foreach ($keys as $key) {
            $refund = $this->refunds->findByKey($key);
            if ($refund !== null && $refund->amount > 0 && $refund->idOrderSlip === 0) {
                $parts[] = $refund;
            }
        }
        if ($parts === []) {
            return 0;
        }
        $slip = $this->orders->addSlip($order, Amount::normalize(array_sum(array_map(static function (RefundRecord $refund): float {
            return $refund->amount;
        }, $parts)), $order->currency));
        foreach ($parts as $refund) {
            $this->refunds->linkSlip($refund, $slip);
            $this->orders->note($order, 'part_' . md5($refund->requestKey), $note($refund));
        }

        return $slip;
    }

    /**
     * The credit slip PrestaShop made for refunds refundForSlip() made.
     *
     * @param list<string> $keys
     */
    public function linkSlip(array $keys, int $idOrderSlip): void
    {
        foreach ($keys as $key) {
            $refund = $this->refunds->findByKey($key);
            if ($refund !== null) {
                $this->refunds->linkSlip($refund, $idOrderSlip);
            }
        }
    }

    /**
     * A credit slip made without the order page's refund forms (another
     * module, PrestaShop's API), after the fact: refunded through Wajub now,
     * or, when Wajub will not, noted for the merchant, who refunds the
     * customer from the Wajub Dashboard. The slip is made already: nothing
     * here can undo it.
     */
    public function refundSlipMade(OrderView $order, int $idOrderSlip, float $amount): void
    {
        $answer = $this->refundForSlip($order, $amount);
        if ($answer['keys'] !== []) {
            $this->linkSlip($answer['keys'], $idOrderSlip);
        }
        if (in_array($answer['status'], [self::REFUSED, self::PARTIAL], true)) {
            $this->orders->note($order, 'slip_not_refunded_' . $idOrderSlip, $this->t->trans(
                'Credit slip %slip% (%amount%) was made, but Wajub did not refund all of it: %reason% Refund the customer the rest from the Wajub Dashboard.',
                ['%slip%' => (string) $idOrderSlip, '%amount%' => $this->money->format($amount, $order->currency), '%reason%' => $answer['message']],
                'Modules.Wajub.Admin'
            ));
        }
    }

    /**
     * A refund's webhook (refund.created, .processing, .succeeded, .failed,
     * .cancelled, .updated) of one of the order's payments, delivered in
     * the payment's own environment.
     *
     * @param array<string, mixed> $data the refund, as its webhooks send it
     */
    public function webhook(OrderView $order, PaymentRecord $record, string $type, array $data): void
    {
        $refundId = DbRefundStore::refundId($data['id'] ?? '');
        if ($refundId === '') {
            $this->logger->info('Webhook refund without a Wajub refund id: not recorded', ['order' => $order->id, 'payment' => $record->paymentId]);

            return;
        }
        // The status the event says, when the refund it carries does not.
        if (trim((string) ($data['status'] ?? '')) === '') {
            $data['status'] = self::statusOfEvent($type);
        }
        $status = DbRefundStore::status($data['status']);

        $this->refunds->note($record, $data, RefundRecord::ORIGIN_WAJUB, !$order->paidOnce);
        $entry = $this->refunds->find($refundId);
        if ($entry === null) {
            return;
        }

        if ($entry->isShops()) {
            // Asked from this order, and no credit slip records it: Wajub did
            // not answer the call, or PrestaShop made no slip once Wajub had
            // refunded. The money is back with the customer: recorded on the
            // order as a refund made in the Wajub Dashboard is. Not while the
            // call that asks for it is open: the slip comes next.
            if ($status === 'succeeded' && $entry->idOrderSlip === 0 && !isset($this->asking[$entry->requestKey])) {
                $this->record($order, $record, $entry);

                return;
            }
            // Asked from a credit slip, which records it already.
            if ($status === 'succeeded') {
                $this->markRefundedIfAll($order);
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                $this->orders->note($order, 'own_refund_failed_' . md5($refundId), $this->t->trans(
                    'Wajub refund %refund% of %amount% (payment %payment%), asked from a credit slip of this order, did not go through (%status%): the customer did not get this money back. Refund them from the Wajub Dashboard, or another way.',
                    ['%refund%' => $refundId, '%amount%' => $this->money->format($entry->amount, $entry->currency), '%payment%' => $record->paymentId, '%status%' => $status],
                    'Modules.Wajub.Admin'
                ));
            }

            return;
        }

        $counted = $record->isCounted();
        if ($counted && $entry->orderUnpaid) {
            // Given back while the order was unpaid: the payer pays it again.
            if ($entry->takesMoney()) {
                $this->settlement->noteRefundOfPart($order, $record, $entry);
            } elseif ($order->paid) {
                $this->noteSurplus($order, $record, $entry);
            }

            return;
        }
        if ($status !== 'succeeded') {
            return;
        }

        if ($counted) {
            // A payment of another environment than the one the order was
            // paid in (a Sandbox attempt, part of an order paid in Live
            // since): its refund gives back nothing the order was paid with.
            $paidIn = $this->ledger->paidIn($order);
            if (!$order->paidOnce || ($paidIn !== '' && $record->environment !== $paidIn)) {
                $this->noteOtherRefund($order, $record, $entry);

                return;
            }
            $this->record($order, $record, $entry);

            return;
        }

        // The order's own payment, not counted yet, refunded while the order
        // is unpaid: every read of it counts it, its refund taken off, with a
        // note of what is then left to pay.
        $current = $this->payments->current($order->id);
        $own = $current !== null && $current->paymentId === $record->paymentId;
        if ($entry->orderUnpaid && $own && $entry->currency === $order->currency && !$order->paidOnce) {
            $this->logger->info('Webhook refund of the order\'s payment before it counts: noted on it', ['order' => $order->id, 'payment' => $record->paymentId, 'refund' => $refundId]);

            return;
        }
        // A second payment the merchant was told to refund, an earlier
        // attempt, or a payment of an order never paid: the money the order
        // was paid with is kept.
        if (!$own || !$order->paidOnce) {
            $this->noteOtherRefund($order, $record, $entry);

            return;
        }
        // The order's own payment of an order paid without it counting (the
        // merchant marked it paid): a refund of the order.
        $this->record($order, $record, $entry);
    }

    /**
     * A refund of a payment that paid the order, recorded on it as a credit
     * slip: the part that takes what Wajub holds for the order below what
     * the order still counts as paid (its total, less what its slips
     * refund). The rest gave back money Wajub held beyond the order's total
     * (a total lowered since, two payments that both went through), or money
     * the order records as refunded already (a slip the merchant made
     * without Wajub, then the refund made in the Wajub Dashboard): noted
     * once, never a slip. The same for one asked from this order that no
     * slip records.
     */
    private function record(OrderView $order, PaymentRecord $record, RefundRecord $entry): void
    {
        if ($entry->idOrderSlip > 0) {
            return;
        }
        $amount = Amount::normalize($entry->amount, $order->currency);
        if ($amount <= 0) {
            return;
        }
        // What Wajub holds for the order once this refund is taken off: the
        // ledger has taken it off already, unless it is in another currency.
        $held = $this->ledger->held($order, $record->environment);
        if ($entry->currency !== $order->currency) {
            $held = max(0.0, $held - $amount);
        }
        $total = Ledger::total($order);
        $refundedOnOrder = Amount::normalize($this->orders->refundedOnOrder($order), $order->currency);
        $paid = Amount::normalize($total - $refundedOnOrder, $order->currency);
        $toRecord = min($amount, max(0.0, Amount::normalize($paid - $held, $order->currency)));
        if ($toRecord + 0.000001 < $amount) {
            $this->noteNotRecorded($order, $record, $entry, Amount::normalize($amount - $toRecord, $order->currency), $toRecord, $held, $paid, $total, $refundedOnOrder);
        }

        if ($toRecord > 0) {
            $slip = $this->orders->addSlip($order, $toRecord);
            $this->refunds->linkSlip($entry, $slip);
            $parameters = ['%refund%' => $entry->refundId, '%amount%' => $this->money->format($toRecord, $order->currency), '%payment%' => $record->paymentId];
            $this->orders->note($order, 'recorded_' . md5($entry->refundId), $entry->isShops()
                ? $this->t->trans('Wajub refund %refund% of %amount% (payment %payment%), asked from this order, went through without a credit slip of it: it is recorded on the order as a credit slip now.', $parameters, 'Modules.Wajub.Admin')
                : $this->t->trans('Wajub refund %refund% of %amount% (payment %payment%), made in the Wajub Dashboard, is recorded on the order as a credit slip.', $parameters, 'Modules.Wajub.Admin'));
            $this->logger->info('Webhook refund recorded on the order', ['order' => $order->id, 'refund' => $entry->refundId, 'amount' => $toRecord]);
        }
        $this->markRefundedIfAll($order);
    }

    /**
     * What of a refund is not recorded on the order, noted once, as what it
     * is: what Wajub held beyond the order's total, money the order records
     * as refunded already ("Refund manually"), or both.
     */
    private function noteNotRecorded(OrderView $order, PaymentRecord $record, RefundRecord $entry, float $left, float $recorded, float $held, float $paid, float $total, float $refundedOnOrder): void
    {
        $key = 'not_recorded_' . md5($entry->refundId);
        if ($refundedOnOrder <= 0) {
            $this->orders->note($order, $key, $this->t->trans(
                '%amount% of Wajub refund %refund% (payment %payment%) gave back what Wajub held beyond the order\'s total: it is not recorded as a refund of the order.',
                ['%amount%' => $this->money->format($left, $order->currency), '%refund%' => $entry->refundId, '%payment%' => $record->paymentId],
                'Modules.Wajub.Admin'
            ));

            return;
        }
        if ($held + $entry->amount <= $total + 0.000001) {
            $this->orders->note($order, $key, $recorded > 0
                ? $this->t->trans(
                    'Wajub refund %refund% (payment %payment%) gives back money this order already records as refunded without Wajub: %left% of it is not recorded a second time, and %recorded% is recorded now.',
                    ['%refund%' => $entry->refundId, '%payment%' => $record->paymentId, '%left%' => $this->money->format($left, $order->currency), '%recorded%' => $this->money->format($recorded, $order->currency)],
                    'Modules.Wajub.Admin'
                )
                : $this->t->trans(
                    'Wajub refund %refund% (payment %payment%) gives back %amount% this order already records as refunded without Wajub: it is not recorded a second time.',
                    ['%refund%' => $entry->refundId, '%payment%' => $record->paymentId, '%amount%' => $this->money->format($left, $order->currency)],
                    'Modules.Wajub.Admin'
                ));

            return;
        }
        $this->orders->note($order, $key, $this->t->trans(
            '%amount% of Wajub refund %refund% (payment %payment%) gave back what Wajub held beyond what the order counts as paid, %paid% (its total of %total% less the %refunded% its credit slips refund): it is not recorded as a refund of the order.',
            [
                '%amount%' => $this->money->format($left, $order->currency),
                '%refund%' => $entry->refundId,
                '%payment%' => $record->paymentId,
                '%paid%' => $this->money->format($paid, $order->currency),
                '%total%' => $this->money->format($total, $order->currency),
                '%refunded%' => $this->money->format($refundedOnOrder, $order->currency),
            ],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * A paid order all of whose Wajub money, in the environment it was paid
     * in, was given back (refunds that succeeded): "Refunded", with
     * PrestaShop's e-mail of that state, once.
     */
    private function markRefundedIfAll(OrderView $order): void
    {
        $order = $this->orders->find($order->id) ?? $order;
        $refunded = $this->orders->refundedState();
        if (!$order->paid || $refunded <= 0 || $order->stateId === $refunded) {
            return;
        }
        $environment = $this->ledger->paidIn($order);
        $counted = $this->ledger->counted($order, $environment);
        if ($counted === []) {
            return;
        }
        foreach ($counted as $paymentId => $collected) {
            if ($this->refunds->succeeded((string) $paymentId, $order->currency) + 0.000001 < $collected) {
                return;
            }
        }
        $this->orders->setState($order, $refunded, true);
        $this->orders->note($order, 'refunded_all', $this->t->trans('Wajub gave back all its payments of this order collected: the order is now "Refunded".', [], 'Modules.Wajub.Admin'));
    }

    /**
     * A refund the payer was asked to pay again did not go through, after
     * the order was paid in full: Wajub holds more than its total.
     */
    private function noteSurplus(OrderView $order, PaymentRecord $record, RefundRecord $entry): void
    {
        $surplus = $this->ledger->held($order, $record->environment) - Ledger::total($order);
        if ($surplus <= 0.000001) {
            return;
        }
        $this->orders->note($order, 'surplus_' . md5($entry->refundId), $this->t->trans(
            'Wajub refund %refund% of payment %payment% did not go through, and the order was paid in full since: Wajub holds %surplus% more than the order\'s total. Refund it from the Wajub Dashboard if the customer should get it back.',
            ['%refund%' => $entry->refundId, '%payment%' => $record->paymentId, '%surplus%' => $this->money->format($surplus, $order->currency)],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * A refund of a payment made for the order that did not pay it (a second
     * payment, an attempt of the other environment, one of an order never
     * paid), noted once: the order is left as it is.
     */
    private function noteOtherRefund(OrderView $order, PaymentRecord $record, RefundRecord $entry): void
    {
        $this->logger->info('Webhook refund of a payment that did not pay the order: order left as it is', ['order' => $order->id, 'payment' => $record->paymentId, 'refund' => $entry->refundId]);
        $this->orders->note($order, 'other_refund_' . md5($entry->refundId), $this->t->trans(
            'Wajub payment %payment%, which did not pay this order, was refunded (%amount%). The order was left as it is.',
            ['%payment%' => $record->paymentId, '%amount%' => $this->money->format($entry->amount, $entry->currency !== '' ? $entry->currency : $order->currency)],
            'Modules.Wajub.Admin'
        ));
    }

    /**
     * The shop's own refunds of the order, in `$environment`, that no credit
     * slip records and that may still give money back: their call went
     * unanswered, and Wajub has said nothing of them since, or only that it
     * made them (refund.created, refund.processing), not yet that they went
     * through. Once one has, its webhook records it on a slip of its own.
     *
     * @return list<RefundRecord>
     */
    private function unrecorded(OrderView $order, string $environment): array
    {
        return array_values(array_filter($this->refunds->forOrder($order->id), static function (RefundRecord $refund) use ($environment): bool {
            return $refund->isShops() && $refund->idOrderSlip === 0 && $refund->environment === $environment
                && in_array($refund->status, [RefundRecord::REQUESTED, 'pending', 'processing'], true);
        }));
    }

    /** The status a refund's event says it is in. */
    public static function statusOfEvent(string $type): string
    {
        switch ($type) {
            case 'refund.succeeded':
            case 'refund.complete':
                return 'succeeded';
            case 'refund.failed':
                return 'failed';
            case 'refund.cancelled':
            case 'refund.canceled':
                return 'cancelled';
            case 'refund.processing':
                return 'processing';
            default:
                return 'pending';
        }
    }

    /**
     * @param list<string> $keys
     *
     * @return array{status: string, message: string, keys: list<string>, refunded: float}
     */
    private static function answer(string $status, string $message = '', array $keys = [], float $refunded = 0.0): array
    {
        return ['status' => $status, 'message' => $message, 'keys' => $keys, 'refunded' => $refunded];
    }
}
