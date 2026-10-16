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

use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderRefundCalculator;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\AbstractRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssuePartialRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssueReturnProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssueStandardRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\VoucherRefundType;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the merchant asks of a refund on the order page of the back office
 * (Partial refund, Standard refund, Return products), read before
 * PrestaShop makes the credit slip, from the form PrestaShop is about to
 * hand its command (the hook actionBeforeUpdateCancelProductFormHandler):
 * the amount the slip gives back, computed as PrestaShop computes it
 * (OrderRefundCalculator, the same commands its form handlers build, then
 * the totals its OrderSlipCreator writes on the slip), and whether a slip,
 * or a voucher, is asked for. PrestaShop 8.1 to 9 build these the same way.
 *
 * The amount is what the customer gets back, tax included, whichever way
 * the customer's group is shown prices: a group shown prices without tax
 * (B2B) has the merchant type amounts without tax, and PrestaShop's own
 * refunded amount is then without tax too, while the slip adds the tax
 * back, and says the customer gets that (total_products_tax_incl +
 * total_shipping_tax_incl, what the order's page lists the slip for, and
 * what the module counts as refunded on the order). Wajub refunds that,
 * not the amount without tax.
 *
 * Cancelling products of an order not paid yet ("Cancel products") refunds
 * nothing: not one of these. Read under the order's lock, which a request
 * that refunded keeps until its end (Refund\PendingSlips): the quantities
 * an earlier refund of the same order took are written by then.
 */
final class SlipRequest
{
    /** The order page's refunds, by the Symfony route of their form. */
    public const ROUTES = [
        'admin_orders_partial_refund' => 'partial',
        'admin_orders_standard_refund' => 'standard',
        'admin_orders_return_product' => 'return',
    ];

    /** The refund the request posts ('partial', 'standard', 'return'), or '' for anything else. */
    public static function action(): string
    {
        try {
            $container = SymfonyContainer::getInstance();
            $stack = $container !== null ? $container->get('request_stack') : null;
            $request = is_object($stack) && method_exists($stack, 'getCurrentRequest') ? $stack->getCurrentRequest() : null;
            $route = is_object($request) ? (string) $request->attributes->get('_route', '') : '';
        } catch (\Throwable $e) {
            return '';
        }

        return self::ROUTES[$route] ?? '';
    }

    /**
     * The amount the credit slip will refund, tax as the order shows it,
     * and whether a slip and a voucher are asked for; null when PrestaShop
     * will refuse the refund itself (and says why), or cannot be read.
     *
     * @param array<string, mixed> $data the form's data, as the hook hands it
     *
     * @return array{amount: float, slip: bool, voucher: bool}|null
     */
    public static function of(string $action, \Order $order, array $data): ?array
    {
        if (!self::allowed($action, $order)) {
            return null;
        }
        $refunds = [];
        foreach (is_array($data['products'] ?? null) ? $data['products'] : [] as $product) {
            if (!is_object($product) || !method_exists($product, 'getOrderDetailId')) {
                continue;
            }
            $id = (int) $product->getOrderDetailId();
            if ($action === 'partial') {
                if (!empty($data['quantity_' . $id]) || !empty((float) ($data['amount_' . $id] ?? 0))) {
                    $refunds[$id] = ['quantity' => $data['quantity_' . $id] ?? 0, 'amount' => $data['amount_' . $id] ?? 0];
                }
            } elseif (!empty($data['selected_' . $id])) {
                $refunds[$id] = ['quantity' => $data['quantity_' . $id] ?? 0];
            }
        }
        $slip = !empty($data['credit_slip']);
        $voucher = !empty($data['voucher']);
        $type = (int) ($data['voucher_refund_type'] ?? VoucherRefundType::PRODUCT_PRICES_EXCLUDING_VOUCHER_REFUND);

        try {
            switch ($action) {
                case 'partial':
                    $command = new IssuePartialRefundCommand((int) $order->id, $refunds, (string) ($data['shipping_amount'] ?? '0'), !empty($data['restock']), $slip, $voucher, $type);
                    $shipping = $command->getShippingCostRefundAmount();
                    break;
                case 'standard':
                    $command = new IssueStandardRefundCommand((int) $order->id, $refunds, !empty($data['shipping']), $slip, $voucher, $type);
                    $shipping = new DecimalNumber((string) ($command->refundShippingCost() ? $order->total_shipping_tax_incl : 0));
                    break;
                case 'return':
                    $command = new IssueReturnProductCommand((int) $order->id, $refunds, !empty($data['restock']), !empty($data['shipping']), $slip, $voucher, $type);
                    $shipping = new DecimalNumber((string) ($command->refundShippingCost() ? $order->total_shipping_tax_incl : 0));
                    break;
                default:
                    return null;
            }

            return ['amount' => self::amount($order, $command, $shipping), 'slip' => $slip, 'voucher' => $voucher];
        } catch (\Throwable $e) {
            // PrestaShop's own handler refuses it the same way, and says why.
            return null;
        }
    }

    /**
     * What PrestaShop's handlers check of the order before they refund
     * (IssuePartialRefundHandler, IssueStandardRefundHandler,
     * IssueReturnProductHandler, the same from 8.1 to 9), after this hook:
     * a refund they refuse gives nothing back, and Wajub must not either. A
     * page opened before the order shipped, or before returns were turned
     * off, still posts the form its buttons no longer show.
     */
    private static function allowed(string $action, \Order $order): bool
    {
        $paid = $order->hasBeenPaid() || $order->hasPayments();
        $returns = (int) \Configuration::get('PS_ORDER_RETURN') > 0;
        switch ($action) {
            case 'partial':
                return $paid;
            case 'standard':
                return $returns && $paid && !$order->hasBeenDelivered();
            case 'return':
                return $returns && (bool) $order->hasBeenDelivered();
            default:
                return false;
        }
    }

    private static function amount(\Order $order, AbstractRefundCommand $command, DecimalNumber $shipping): float
    {
        $summary = (new OrderRefundCalculator())->computeOrderRefund(
            $order,
            $command->getOrderDetailRefunds(),
            $shipping,
            $command->getVoucherRefundType(),
            $command->getVoucherRefundAmount()
        );
        if ($summary->isTaxIncluded() || $summary->isVoucherChosen()) {
            // Typed with tax: what PrestaShop computes is what the customer
            // gets back, the order's vouchers taken off when the merchant
            // chose so (the slip's document says the same).
            return (float) $summary->getRefundedAmount();
        }

        // Typed without tax: the slip's totals with tax, as OrderSlipCreator
        // writes them, product by product, rounded as the shop rounds them.
        $precision = $summary->getPrecision();
        $lines = [];
        foreach ($summary->getProductRefunds() as $refund) {
            $detail = new \OrderDetail((int) $refund['id_order_detail']);
            $calculator = $detail->getTaxCalculator();
            $lines[] = [
                'price' => (float) $refund['unit_price'],
                'quantity' => (int) $refund['quantity'],
                'group' => (int) $detail->id_tax_rules_group,
                'tax' => static function (float $amount) use ($calculator): float {
                    return (float) $calculator->addTaxes($amount);
                },
            ];
        }
        $address = (int) $order->id_address_invoice;
        $products = SlipTotals::productsTaxIncluded(
            $lines,
            (int) \Configuration::get('PS_ROUND_TYPE'),
            $precision,
            static function (int $group, float $sum) use ($address): float {
                return (float) \TaxManagerFactory::getManager(\Address::initialize($address, true), $group)->getTaxCalculator()->addTaxes($sum);
            },
            static function (float $amount, int $precision): float {
                return (float) \Tools::ps_round($amount, $precision);
            }
        );
        $products -= (float) $summary->getVoucherAmount();

        $shippingTaxIncluded = 0.0;
        $refundedShipping = (float) $summary->getRefundedShipping();
        if ($refundedShipping > 0) {
            $calculator = (new \Carrier((int) $order->id_carrier))->getTaxCalculator(\Address::initialize((int) $order->id_address_delivery, false));
            $shippingTaxIncluded = $calculator instanceof \TaxCalculator
                ? (float) \Tools::ps_round($calculator->addTaxes($refundedShipping), $precision)
                : $refundedShipping;
        }

        return $products + $shippingTaxIncluded;
    }
}
