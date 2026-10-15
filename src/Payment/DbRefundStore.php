<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Payment;

use Wajub\PrestaShop\Support\Amount;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The RefundStore on the module's `wajub_refund` table (Install\Schema),
 * unique by the refund's Wajub id, and by the key the shop sent its own
 * refunds with. Read without PrestaShop's query cache: a webhook and the
 * back office's call write rows the other reads.
 */
final class DbRefundStore implements RefundStore
{
    public const TABLE = 'wajub_refund';

    /** How far along a refund's life a status is: a status never replaces one further along. */
    public const RANK = RefundStore::RANK;

    public function note(PaymentRecord $payment, array $refund, string $origin, bool $orderUnpaid = false): bool
    {
        $refundId = self::refundId($refund['id'] ?? '');
        if ($refundId === '') {
            return false;
        }
        $status = self::status($refund['status'] ?? '');
        $db = \Db::getInstance();

        // One the shop asked for: the row its call wrote, found by its key.
        $metadata = is_array($refund['metadata'] ?? null) ? $refund['metadata'] : [];
        $requestKey = self::requestKey($metadata['request_key'] ?? '');
        $own = $requestKey !== '' ? $this->findByKey($requestKey) : null;
        if ($own !== null && ($own->refundId === '' || $own->refundId === $refundId)) {
            $this->moveOn($own, $refundId, $status);

            return false;
        }

        $currency = strtoupper(Text::code($refund['currency'] ?? '', 3)) ?: $payment->currency;
        $amount = is_numeric($refund['amount'] ?? null) ? max(0.0, (float) $refund['amount']) : 0.0;
        $now = date('Y-m-d H:i:s');
        $db->execute(
            'INSERT IGNORE INTO `' . $this->table() . '` (`refund_id`, `payment_id`, `id_shop`, `id_order`, `environment`, `origin`, `status`, `currency`, `amount`, `reason`, `order_unpaid`, `date_add`, `date_upd`) VALUES ('
            . '\'' . pSQL($refundId) . '\', \'' . pSQL($payment->paymentId) . '\', ' . (int) $payment->idShop . ', ' . (int) $payment->idOrder . ', '
            . '\'' . pSQL($payment->environment) . '\', \'' . pSQL($origin) . '\', \'' . pSQL($status) . '\', \'' . pSQL($currency) . '\', '
            . self::decimal(Amount::normalize($amount, $currency)) . ', \'' . pSQL(Text::code($refund['reason'] ?? '', 64)) . '\', ' . ($orderUnpaid ? 1 : 0) . ', \'' . $now . '\', \'' . $now . '\')',
            false
        );
        if ((int) $db->Affected_Rows() === 1) {
            return true;
        }

        // Known already: its status moves on, never back.
        $known = $this->find($refundId);
        if ($known !== null) {
            $this->moveOn($known, $refundId, $status);
        }

        return false;
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
        $refundId = self::refundId($refundId);
        if ($refundId === '') {
            return null;
        }
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . $this->table() . '` WHERE `refund_id` = \'' . pSQL($refundId) . '\'', false);

        return is_array($row) ? self::record($row) : null;
    }

    public function findByKey(string $requestKey): ?RefundRecord
    {
        $requestKey = self::requestKey($requestKey);
        if ($requestKey === '') {
            return null;
        }
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . $this->table() . '` WHERE `request_key` = \'' . pSQL($requestKey) . '\'', false);

        return is_array($row) ? self::record($row) : null;
    }

    public function forOrder(int $idOrder): array
    {
        if ($idOrder <= 0) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT * FROM `' . $this->table() . '` WHERE `id_order` = ' . (int) $idOrder . ' ORDER BY `id_wajub_refund` ASC',
            true,
            false
        );
        $records = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $records[] = self::record($row);
            }
        }

        return $records;
    }

    public function requested(PaymentRecord $payment, string $requestKey, float $amount, string $reason): void
    {
        $requestKey = self::requestKey($requestKey);
        if ($requestKey === '') {
            throw new \InvalidArgumentException('A refund the shop asks for needs its key');
        }
        $now = date('Y-m-d H:i:s');
        \Db::getInstance()->insert(self::TABLE, [
            'request_key' => pSQL($requestKey),
            'payment_id' => pSQL($payment->paymentId),
            'id_shop' => (int) $payment->idShop,
            'id_order' => (int) $payment->idOrder,
            'environment' => pSQL($payment->environment),
            'origin' => RefundRecord::ORIGIN_PRESTASHOP,
            'status' => RefundRecord::REQUESTED,
            'currency' => pSQL($payment->currency),
            'amount' => self::decimal(Amount::normalize($amount, $payment->currency)),
            'reason' => pSQL(Text::code($reason, 64)),
            'order_unpaid' => 0,
            'date_add' => $now,
            'date_upd' => $now,
        ], false, false);
    }

    public function answered(string $requestKey, array $refund): void
    {
        $own = $this->findByKey($requestKey);
        if ($own === null) {
            return;
        }
        $this->moveOn($own, self::refundId($refund['id'] ?? ''), self::status($refund['status'] ?? ''));
    }

    public function forget(string $requestKey): void
    {
        $requestKey = self::requestKey($requestKey);
        if ($requestKey === '') {
            return;
        }
        // Only while Wajub never named it: one its webhook told of moved money.
        \Db::getInstance()->delete(self::TABLE, '`request_key` = \'' . pSQL($requestKey) . '\' AND `refund_id` IS NULL AND `status` = \'' . RefundRecord::REQUESTED . '\'', 1, false);
    }

    public function linkSlip(RefundRecord $refund, int $idOrderSlip): void
    {
        if ($refund->id <= 0 || $idOrderSlip <= 0) {
            return;
        }
        \Db::getInstance()->update(self::TABLE, ['id_order_slip' => (int) $idOrderSlip, 'date_upd' => date('Y-m-d H:i:s')], '`id_wajub_refund` = ' . (int) $refund->id, 1, false, false);
        $refund->idOrderSlip = $idOrderSlip;
    }

    /** A refund's status as the module ranks it: `canceled` is `cancelled`. */
    public static function status(mixed $status): string
    {
        $status = strtolower(Text::code($status, 32));

        return $status === 'canceled' ? 'cancelled' : ($status !== '' ? $status : 'pending');
    }

    /** Whether a refund in `$known` may move to `$status`: only forward. */
    public static function movesOn(string $known, string $status): bool
    {
        return isset(self::RANK[$status]) && (!isset(self::RANK[$known]) || self::RANK[$status] > self::RANK[$known]);
    }

    /** A Wajub refund id, or ''. */
    public static function refundId(mixed $id): string
    {
        $id = Text::code($id, 80);

        return preg_match('/^rfd_[A-Za-z0-9_]{1,64}$/', $id) === 1 ? $id : '';
    }

    /** A key the shop sends its own refunds with, or ''. */
    public static function requestKey(mixed $key): string
    {
        $key = Text::code($key, 64);

        return preg_match('/^[a-f0-9]{32}$/', $key) === 1 ? $key : '';
    }

    /**
     * Gives a row Wajub's id when it has none, and a status further along.
     */
    private function moveOn(RefundRecord $refund, string $refundId, string $status): void
    {
        $values = [];
        if ($refund->refundId === '' && $refundId !== '' && $this->find($refundId) === null) {
            $values['refund_id'] = pSQL($refundId);
            $refund->refundId = $refundId;
        }
        if (self::movesOn($refund->status, $status)) {
            $values['status'] = pSQL($status);
            $refund->status = $status;
        }
        if ($values === []) {
            return;
        }
        $values['date_upd'] = date('Y-m-d H:i:s');
        \Db::getInstance()->update(self::TABLE, $values, '`id_wajub_refund` = ' . (int) $refund->id, 1, false, false);
    }

    /**
     * @param list<string> $statuses
     */
    private function sum(string $paymentId, string $currency, array $statuses): float
    {
        $sum = \Db::getInstance()->getValue(
            'SELECT SUM(`amount`) FROM `' . $this->table() . '` WHERE `payment_id` = \'' . pSQL($paymentId) . '\''
            . ' AND `currency` = \'' . pSQL($currency) . '\' AND `status` IN (\'' . implode('\', \'', array_map('pSQL', $statuses)) . '\')',
            false
        );

        return Amount::normalize((float) $sum, $currency);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function record(array $row): RefundRecord
    {
        $record = new RefundRecord();
        $record->id = (int) ($row['id_wajub_refund'] ?? 0);
        $record->refundId = (string) ($row['refund_id'] ?? '');
        $record->requestKey = (string) ($row['request_key'] ?? '');
        $record->paymentId = (string) ($row['payment_id'] ?? '');
        $record->idShop = (int) ($row['id_shop'] ?? 0);
        $record->idOrder = (int) ($row['id_order'] ?? 0);
        $record->idOrderSlip = (int) ($row['id_order_slip'] ?? 0);
        $record->environment = (string) ($row['environment'] ?? '');
        $record->origin = (string) ($row['origin'] ?? '');
        $record->status = (string) ($row['status'] ?? '');
        $record->currency = (string) ($row['currency'] ?? '');
        $record->amount = (float) ($row['amount'] ?? 0);
        $record->reason = (string) ($row['reason'] ?? '');
        $record->orderUnpaid = (int) ($row['order_unpaid'] ?? 0) === 1;
        $record->dateAdd = (string) ($row['date_add'] ?? '');

        return $record;
    }

    private static function decimal(float $amount): string
    {
        return number_format($amount, 6, '.', '');
    }

    private function table(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }
}
