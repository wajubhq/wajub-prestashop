<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Privacy;

use Wajub\PrestaShop\Order\HeldConfirmations;
use Wajub\PrestaShop\Payment\ChannelLabels;
use Wajub\PrestaShop\Payment\MethodTexts;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Payment\RefundStore;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the module keeps of a customer, for PrestaShop's GDPR module
 * (psgdpr, through the hooks actionExportGDPRData and
 * actionDeleteGDPRCustomer).
 *
 * The module keeps no name, e-mail address, phone number or card: those go
 * to Wajub with each payment, and stay there (Wajub answers for them). Here:
 *
 * - the Wajub payments of the customer's orders (wajub_payment: ids,
 *   amounts, the way they paid, such as "MTN Mobile Money", the customer's
 *   id), with their refunds (wajub_refund) and the notes on their orders
 *   (wajub_order_note), which name payments, never a person;
 * - an order's confirmation e-mail held until it is paid (wajub_state),
 *   which holds the customer's name, e-mail address and addresses.
 *
 * An export lists the payments. An erasure deletes the e-mails held and
 * unlinks the payments from the customer: they stay as the record of the
 * orders' money, which PrestaShop keeps too (the orders, their payments),
 * and which a refund needs.
 */
final class CustomerData
{
    /** @var PaymentStore */
    private $payments;

    /** @var RefundStore */
    private $refunds;

    /** @var HeldConfirmations */
    private $confirmations;

    /** @var Translator */
    private $t;

    public function __construct(PaymentStore $payments, RefundStore $refunds, HeldConfirmations $confirmations, Translator $t)
    {
        $this->payments = $payments;
        $this->refunds = $refunds;
        $this->confirmations = $confirmations;
        $this->t = $t;
    }

    /**
     * The customers the GDPR module names (the hooks' parameter). A customer
     * (its `id`, with its e-mail address): that customer alone, as the GDPR
     * module exports and erases its own data. PrestaShop lets anyone open an
     * account with the e-mail address of a guest, unverified: looked up by
     * address, the guest's payments would go to that account's export, and
     * its erasure would touch them. An e-mail address alone (a request the
     * back office makes for someone with no account): its customers, guests
     * included. A phone number alone names nobody here.
     *
     * @param mixed $params
     * @param callable(string): list<int> $byEmail the customers with this e-mail address
     *
     * @return list<int>
     */
    public static function customerIds($params, callable $byEmail): array
    {
        $params = is_array($params) ? $params : [];
        $id = (int) ($params['id'] ?? $params['id_customer'] ?? 0);
        if ($id > 0) {
            return [$id];
        }
        $email = is_string($params['email'] ?? null) ? trim($params['email']) : '';

        return $email !== '' ? self::ids($byEmail($email)) : [];
    }

    /**
     * One row per Wajub payment of the customer's orders, with named
     * columns, as the GDPR module writes them into its CSV and PDF exports.
     *
     * @param list<int> $customerIds the customer's ids (a customer with an account and their guest checkouts)
     *
     * @return list<array<string, string>>
     */
    public function export(array $customerIds): array
    {
        $rows = [];
        foreach (self::ids($customerIds) as $idCustomer) {
            foreach ($this->payments->forCustomer($idCustomer) as $payment) {
                $rows[] = $this->row($payment);
            }
        }

        return $rows;
    }

    /**
     * Erases what identifies the customer: the confirmation e-mails held for
     * their orders, and their id on the payments.
     *
     * @param list<int> $customerIds
     * @param list<int> $orderIds the customer's orders, as PrestaShop lists them
     *
     * @return array{confirmations: int, payments: int}
     */
    public function erase(array $customerIds, array $orderIds): array
    {
        $orders = [];
        foreach (self::ids($customerIds) as $idCustomer) {
            foreach ($this->payments->forCustomer($idCustomer) as $payment) {
                $orders[] = $payment->idOrder;
            }
        }
        $confirmations = 0;
        foreach (self::ids(array_merge($orders, $orderIds)) as $idOrder) {
            if ($this->confirmations->forget($idOrder)) {
                ++$confirmations;
            }
        }
        $payments = 0;
        foreach (self::ids($customerIds) as $idCustomer) {
            $payments += $this->payments->forgetCustomer($idCustomer);
        }

        return ['confirmations' => $confirmations, 'payments' => $payments];
    }

    /**
     * @return array<string, string>
     */
    private function row(PaymentRecord $payment): array
    {
        $t = $this->t;
        $refunded = $payment->currency !== '' ? $this->refunds->succeeded($payment->paymentId, $payment->currency) : 0.0;

        return [
            $t->trans('Date', [], 'Modules.Wajub.Shop') => $payment->dateAdd,
            $t->trans('Order', [], 'Modules.Wajub.Shop') => (string) $payment->idOrder,
            $t->trans('Wajub payment ID', [], 'Modules.Wajub.Shop') => $payment->paymentId,
            // In words, as the customer read them: never Wajub's codes.
            $t->trans('Payment method', [], 'Modules.Wajub.Shop') => $payment->channel !== '' ? ChannelLabels::label($payment->channel, $t) : MethodTexts::defaultTitle($payment->method, $t),
            $t->trans('Status', [], 'Modules.Wajub.Shop') => $this->status($payment->status),
            $t->trans('Currency', [], 'Modules.Wajub.Shop') => $payment->currency,
            $t->trans('Amount', [], 'Modules.Wajub.Shop') => self::amount($payment->amount),
            $t->trans('Amount paid', [], 'Modules.Wajub.Shop') => self::amount($payment->amountPaid),
            $t->trans('Amount refunded', [], 'Modules.Wajub.Shop') => self::amount($refunded),
            $t->trans('Environment', [], 'Modules.Wajub.Shop') => $payment->environment === 'live' ? $t->trans('Live', [], 'Modules.Wajub.Shop') : $t->trans('Sandbox', [], 'Modules.Wajub.Shop'),
        ];
    }

    /** A payment's status as the customer reads it. */
    private function status(string $status): string
    {
        $t = $this->t;
        switch (strtolower($status)) {
            case 'succeeded':
            case 'complete':
            case 'paid':
                return $t->trans('Paid', [], 'Modules.Wajub.Shop');
            case 'processing':
                return $t->trans('Processing', [], 'Modules.Wajub.Shop');
            case 'partial':
                return $t->trans('Paying in instalments', [], 'Modules.Wajub.Shop');
            case 'failed':
            case 'rejected':
                return $t->trans('Failed', [], 'Modules.Wajub.Shop');
            case 'canceled':
            case 'cancelled':
            case 'abandoned':
            case 'expired':
                return $t->trans('Cancelled', [], 'Modules.Wajub.Shop');
            case 'refunded':
                return $t->trans('Refunded', [], 'Modules.Wajub.Shop');
            case 'partially_refunded':
            case 'partially-refunded':
                return $t->trans('Partially refunded', [], 'Modules.Wajub.Shop');
            default:
                return $t->trans('Pending', [], 'Modules.Wajub.Shop');
        }
    }

    private static function amount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.');
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }
}
