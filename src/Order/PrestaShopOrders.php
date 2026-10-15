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
    public const NOTE_TABLE = 'wajub_order_note';

    public function find(int $idOrder): ?OrderView
    {
        if ($idOrder <= 0) {
            return null;
        }
        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return null;
        }

        // The orders one cart became, one per package, under one reference.
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_order`, `total_paid_tax_incl` FROM `' . _DB_PREFIX_ . 'orders`'
            . ' WHERE `reference` = \'' . pSQL((string) $order->reference) . '\' AND `id_cart` = ' . (int) $order->id_cart
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
            $total = (float) $order->total_paid_tax_incl;
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
            array_values(array_unique(array_merge([$idOrder], $ids)))
        );
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
            // The payments the order holds go on its invoice: PrestaShop
            // adds none for the rest, which Wajub's already are.
            $history->changeIdOrderState($stateId, $prestashopOrder, true);
            if ($mail) {
                $history->addWithemail(true);
            } else {
                $history->add(true);
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
        $prestashopOrder = new \Order($order->id);
        if (!\Validate::isLoadedObject($prestashopOrder) || $amount <= 0) {
            return 0;
        }
        $currency = new \Currency((int) $prestashopOrder->id_currency);
        // As OrderSlipCreator makes the slip of a refund of an amount alone
        // (VoucherRefundType::SPECIFIC_AMOUNT_REFUND): the amount, no
        // products, no shipping. No hook: actionOrderSlipAdd would ask Wajub
        // to refund it again.
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
            throw new \RuntimeException('The credit slip could not be added to order ' . $order->id);
        }

        return (int) $slip->id;
    }

    public function lastSlip(OrderView $order): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT MAX(`id_order_slip`) FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` = ' . (int) $order->id,
            false
        );
    }

    public function refundedOnOrder(OrderView $order): float
    {
        $ids = array_map('intval', $order->ids !== [] ? $order->ids : [$order->id]);
        // A slip of products refunds their prices; one of an amount alone
        // (no products) refunds that amount.
        $sum = \Db::getInstance()->getValue(
            'SELECT SUM(IF(`total_products_tax_incl` > 0, `total_products_tax_incl`, `amount`) + `total_shipping_tax_incl`)'
            . ' FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` IN (' . implode(', ', $ids) . ')',
            false
        );

        return (float) $sum;
    }

    public function inState(int $stateId, int $limit, array $skip = []): array
    {
        if ($stateId <= 0 || $limit <= 0) {
            return [];
        }
        $skip = array_values(array_filter(array_map('intval', $skip)));
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_order`, `date_add` FROM `' . _DB_PREFIX_ . 'orders` WHERE `current_state` = ' . (int) $stateId
            . ' AND `module` = \'wajub\'' . ($skip !== [] ? ' AND `id_order` NOT IN (' . implode(', ', $skip) . ')' : '')
            . ' ORDER BY `date_add` ASC, `id_order` ASC LIMIT ' . (int) $limit,
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
