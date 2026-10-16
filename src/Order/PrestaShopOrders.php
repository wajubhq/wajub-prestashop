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

use Wajub\PrestaShop\Refund\SlipTotals;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * ShopOrders on PrestaShop's own orders, states and payments, and the
 * module's `wajub_order_note` table.
 */
final class PrestaShopOrders implements ShopOrders
{
    /** @var int how deep the module is in setState() */
    private static $settingState = 0;

    public const NOTE_TABLE = 'wajub_order_note';

    /**
     * A cart that ships in several packages (products only some carriers
     * take, several warehouses) becomes several orders, one per package,
     * under one reference: PaymentModule::validateOrder() makes them all,
     * and PrestaShop links each payment to the reference, so that every one
     * of them shows it (Order::addOrderPayment()). The module reads them as
     * one order, paid with one payment: the first of them (the lowest id)
     * holds the Wajub payments, the notes and the lock, its total is theirs,
     * a state goes to each (setState()), and a credit slip of any of them
     * is refunded from the group's payments.
     */
    public function find(int $idOrder): ?OrderView
    {
        if ($idOrder <= 0) {
            return null;
        }
        $asked = new \Order($idOrder);
        if (!\Validate::isLoadedObject($asked)) {
            return null;
        }

        // The orders one cart became, one per package, under one reference.
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_order`, `total_paid_tax_incl` FROM `' . _DB_PREFIX_ . 'orders`'
            . ' WHERE `reference` = \'' . pSQL((string) $asked->reference) . '\' AND `id_cart` = ' . (int) $asked->id_cart
            . ' ORDER BY `id_order` ASC',
            true,
            false
        );
        $ids = [];
        $total = 0.0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $ids[] = (int) $row['id_order'];
            $total += (float) $row['total_paid_tax_incl'];
        }
        if (!in_array($idOrder, $ids, true)) {
            $ids = [$idOrder];
            $total = (float) $asked->total_paid_tax_incl;
        }
        $order = $ids[0] === $idOrder ? $asked : new \Order($ids[0]);
        if (!\Validate::isLoadedObject($order)) {
            $order = $asked;
            $ids = [$idOrder];
            $total = (float) $asked->total_paid_tax_incl;
        }

        $state = new \OrderState((int) $order->current_state);
        $paid = \Validate::isLoadedObject($state) && (bool) $state->paid;
        $currency = new \Currency((int) $order->id_currency);

        return new OrderView(
            (int) $order->id,
            (string) $order->reference,
            (int) $order->id_shop,
            (int) $order->id_cart,
            (int) $order->id_customer,
            (int) $order->id_lang,
            strtoupper((string) $currency->iso_code),
            $total,
            (int) $order->current_state,
            $paid,
            $paid || (int) $order->hasBeenPaid() > 0,
            (string) $order->module,
            (string) $order->payment,
            (string) $order->secure_key,
            $ids
        );
    }

    public function leadId(int $idOrder): int
    {
        if ($idOrder <= 0) {
            return $idOrder;
        }
        $lead = (int) \Db::getInstance()->getValue(
            'SELECT MIN(o.`id_order`) FROM `' . _DB_PREFIX_ . 'orders` o'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'orders` asked ON asked.`reference` = o.`reference` AND asked.`id_cart` = o.`id_cart`'
            . ' WHERE asked.`id_order` = ' . (int) $idOrder,
            false
        );

        return $lead > 0 ? $lead : $idOrder;
    }

    public function addPayment(OrderView $order, float $amount, string $transactionId): int
    {
        $prestashopOrder = new \Order($order->id);
        if (!\Validate::isLoadedObject($prestashopOrder)) {
            throw new \RuntimeException('Order not found: ' . $order->id);
        }
        $currency = new \Currency((int) $prestashopOrder->id_currency);
        if (!$prestashopOrder->addOrderPayment((string) $amount, (string) $prestashopOrder->payment, $transactionId, $currency)) {
            throw new \RuntimeException('The payment could not be added to order ' . $order->id);
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT `id_order_payment` FROM `' . _DB_PREFIX_ . 'order_payment`'
            . ' WHERE `order_reference` = \'' . pSQL((string) $prestashopOrder->reference) . '\' AND `transaction_id` = \'' . pSQL($transactionId) . '\''
            . ' ORDER BY `id_order_payment` DESC',
            false
        );
    }

    /**
     * Whether the module itself is moving an order to another state now
     * (setState()): PrestaShop's actionOrderStatusPostUpdate then hears the
     * module, not the merchant.
     */
    public static function settingState(): bool
    {
        return self::$settingState > 0;
    }

    public function setState(OrderView $order, int $stateId, bool $mail): void
    {
        if ($stateId <= 0) {
            return;
        }
        foreach ($order->ids !== [] ? $order->ids : [$order->id] as $idOrder) {
            $prestashopOrder = new \Order($idOrder);
            if (!\Validate::isLoadedObject($prestashopOrder) || (int) $prestashopOrder->current_state === $stateId) {
                continue;
            }
            $history = new \OrderHistory();
            $history->id_order = (int) $idOrder;
            $history->id_employee = 0;
            ++self::$settingState;
            try {
                // The payments the order holds go on its invoice: PrestaShop
                // adds none for the rest, which Wajub's already are.
                $history->changeIdOrderState($stateId, $prestashopOrder, true);
                if ($mail) {
                    $history->addWithemail(true);
                } else {
                    $history->add(true);
                }
            } finally {
                --self::$settingState;
            }
        }
    }

    public function note(OrderView $order, string $key, string $message): bool
    {
        $db = \Db::getInstance();
        $db->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . self::NOTE_TABLE . '` (`id_order`, `id_shop`, `note_key`, `message`, `date_add`) VALUES ('
            . (int) $order->id . ', ' . (int) $order->idShop . ', \'' . pSQL(substr($key, 0, 64)) . '\', \'' . pSQL(Text::lines($message, 2000)) . '\', \'' . date('Y-m-d H:i:s') . '\')',
            false
        );

        return (int) $db->Affected_Rows() === 1;
    }

    public function forgetNote(OrderView $order, string $key): void
    {
        \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::NOTE_TABLE . '` WHERE `id_order` = ' . (int) $order->id . ' AND `note_key` = \'' . pSQL(substr($key, 0, 64)) . '\'',
            false
        );
    }

    public function notes(int $idOrder): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `note_key`, `message`, `date_add` FROM `' . _DB_PREFIX_ . self::NOTE_TABLE . '` WHERE `id_order` = ' . (int) $idOrder
            . ' AND `message` <> \'\' ORDER BY `id_wajub_order_note` DESC',
            true,
            false
        );

        $notes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $notes[] = ['key' => (string) $row['note_key'], 'message' => (string) $row['message'], 'date' => (string) $row['date_add']];
        }

        return $notes;
    }

    public function addSlip(OrderView $order, float $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }
        // The orders a cart became each take up to what is left of their
        // own total, the first one first (Ledger::slipShares()).
        $ids = $order->ids !== [] ? $order->ids : [$order->id];
        $rooms = [];
        foreach ($ids as $idOrder) {
            $total = (float) \Db::getInstance()->getValue('SELECT `total_paid_tax_incl` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . (int) $idOrder, false);
            $rooms[$idOrder] = $total - $this->givenBack([$idOrder]);
        }
        $first = 0;
        foreach (Ledger::slipShares($amount, $rooms, $order->currency) as $idOrder => $share) {
            $slip = $this->slipOf($idOrder, $share);
            $first = $first > 0 ? $first : $slip;
        }

        return $first;
    }

    /**
     * A credit slip of `$amount` alone on one order, as OrderSlipCreator
     * makes the slip of a refund of an amount alone
     * (VoucherRefundType::SPECIFIC_AMOUNT_REFUND): the amount, no products,
     * no shipping. No hook: actionOrderSlipAdd would ask Wajub to refund it
     * again.
     */
    private function slipOf(int $idOrder, float $amount): int
    {
        $prestashopOrder = new \Order($idOrder);
        if (!\Validate::isLoadedObject($prestashopOrder)) {
            return 0;
        }
        $currency = new \Currency((int) $prestashopOrder->id_currency);
        $slip = new \OrderSlip();
        $slip->id_customer = (int) $prestashopOrder->id_customer;
        $slip->id_order = (int) $prestashopOrder->id;
        $slip->conversion_rate = (float) $currency->conversion_rate;
        $slip->amount = $amount;
        $slip->shipping_cost = false;
        $slip->shipping_cost_amount = 0;
        $slip->total_products_tax_excl = 0;
        $slip->total_products_tax_incl = 0;
        $slip->total_shipping_tax_excl = 0;
        $slip->total_shipping_tax_incl = 0;
        $slip->partial = 0;
        $slip->order_slip_type = 2;
        if (!$slip->add()) {
            throw new \RuntimeException('The credit slip could not be added to order ' . $idOrder);
        }

        return (int) $slip->id;
    }

    public function lastSlip(OrderView $order): int
    {
        // The slip just made is the newest of the group, whichever of its
        // orders the merchant refunded.
        $ids = array_map('intval', $order->ids !== [] ? $order->ids : [$order->id]);

        return (int) \Db::getInstance()->getValue(
            'SELECT MAX(`id_order_slip`) FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` IN (' . implode(', ', $ids) . ')',
            false
        );
    }

    public function refundedOnOrder(OrderView $order): float
    {
        return $this->givenBack(array_map('intval', $order->ids !== [] ? $order->ids : [$order->id]));
    }

    public function slipGivesBack(int $idOrderSlip): float
    {
        return array_sum($this->slipsGiveBack('s.`id_order_slip` = ' . (int) $idOrderSlip));
    }

    /**
     * What the credit slips of `$ids` give back, tax included
     * (Refund\SlipTotals::givenBack()).
     *
     * @param list<int> $ids
     */
    private function givenBack(array $ids): float
    {
        return $ids === [] ? 0.0 : array_sum($this->slipsGiveBack('s.`id_order` IN (' . implode(', ', array_map('intval', $ids)) . ')'));
    }

    /**
     * What each slip `$where` names gives back, by slip id: its row, the
     * totals of its lines, and its order's vouchers.
     *
     * @return array<int, float>
     */
    private function slipsGiveBack(string $where): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT s.`id_order_slip`, s.`amount`, s.`total_products_tax_incl`, s.`total_products_tax_excl`, s.`total_shipping_tax_incl`, s.`order_slip_type`, o.`total_discounts`,'
            . ' (SELECT IFNULL(SUM(d.`amount_tax_incl`), 0) FROM `' . _DB_PREFIX_ . 'order_slip_detail` d WHERE d.`id_order_slip` = s.`id_order_slip`) AS `details_incl`,'
            . ' (SELECT IFNULL(SUM(d.`amount_tax_excl`), 0) FROM `' . _DB_PREFIX_ . 'order_slip_detail` d WHERE d.`id_order_slip` = s.`id_order_slip`) AS `details_excl`'
            . ' FROM `' . _DB_PREFIX_ . 'order_slip` s INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = s.`id_order`'
            . ' WHERE ' . $where,
            true,
            false
        );
        $given = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $given[(int) $row['id_order_slip']] = SlipTotals::givenBack([
                'amount' => (float) $row['amount'],
                'products_incl' => (float) $row['total_products_tax_incl'],
                'products_excl' => (float) $row['total_products_tax_excl'],
                'shipping_incl' => (float) $row['total_shipping_tax_incl'],
                'type' => (int) $row['order_slip_type'],
                'details_incl' => (float) $row['details_incl'],
                'details_excl' => (float) $row['details_excl'],
                'discounts' => (float) $row['total_discounts'],
            ]);
        }

        return $given;
    }

    public function inState(int $stateId, int $limit, array $skip = []): array
    {
        if ($stateId <= 0 || $limit <= 0) {
            return [];
        }
        $skip = array_values(array_filter(array_map('intval', $skip)));
        // The first order of each group only (find()): the others are the same order.
        $rows = \Db::getInstance()->executeS(
            'SELECT o.`id_order`, o.`date_add` FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`current_state` = ' . (int) $stateId
            . ' AND o.`module` = \'wajub\'' . ($skip !== [] ? ' AND o.`id_order` NOT IN (' . implode(', ', $skip) . ')' : '')
            . ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` first WHERE first.`reference` = o.`reference` AND first.`id_cart` = o.`id_cart` AND first.`id_order` < o.`id_order`)'
            . ' ORDER BY o.`date_add` ASC, o.`id_order` ASC LIMIT ' . (int) $limit,
            true,
            false
        );
        $orders = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $placed = strtotime((string) $row['date_add']);
            $orders[(int) $row['id_order']] = $placed !== false ? $placed : time();
        }

        return $orders;
    }

    public function paidState(): int
    {
        return (int) \Configuration::get('PS_OS_PAYMENT');
    }

    public function errorState(): int
    {
        return (int) \Configuration::get('PS_OS_ERROR');
    }

    public function canceledState(): int
    {
        return (int) \Configuration::get('PS_OS_CANCELED');
    }

    public function refundedState(): int
    {
        return (int) \Configuration::get('PS_OS_REFUND');
    }
}
