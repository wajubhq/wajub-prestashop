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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The PaymentStore on the module's `wajub_payment` table (Install\Schema),
 * read without PrestaShop's query cache: a webhook and the payer's return
 * read rows the other has just written.
 */
final class DbPaymentStore implements PaymentStore
{
    public const TABLE = 'wajub_payment';

    public function find(string $paymentId): ?PaymentRecord
    {
        if ($paymentId === '') {
            return null;
        }
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . $this->table() . '` WHERE `payment_id` = \'' . pSQL($paymentId) . '\'', false);

        return is_array($row) ? self::record($row) : null;
    }

    public function preparedFor(int $idCart, string $method): ?PaymentRecord
    {
        if ($idCart <= 0) {
            return null;
        }
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . $this->table() . '` WHERE `id_cart` = ' . (int) $idCart
            . ' AND `method` = \'' . pSQL($method) . '\' AND `state` = \'' . PaymentRecord::PREPARED . '\' AND `id_order` = 0'
            . ' ORDER BY `id_wajub_payment` DESC',
            false
        );

        return is_array($row) ? self::record($row) : null;
    }

    public function forOrder(int $idOrder): array
    {
        if ($idOrder <= 0) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT * FROM `' . $this->table() . '` WHERE `id_order` = ' . (int) $idOrder
            . ' AND `state` <> \'' . PaymentRecord::PREPARED . '\' ORDER BY `id_wajub_payment` ASC',
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

    public function current(int $idOrder): ?PaymentRecord
    {
        $records = $this->forOrder($idOrder);

        return $records === [] ? null : $records[count($records) - 1];
    }

    public function uncounted(int $limit, array $skip = []): array
    {
        if ($limit <= 0) {
            return [];
        }
        $skip = array_values(array_filter(array_map('intval', $skip)));
        $rows = \Db::getInstance()->executeS(
            'SELECT * FROM `' . $this->table() . '` WHERE `state` = \'' . PaymentRecord::UNCOUNTED . '\' AND `id_order` > 0'
            . ($skip !== [] ? ' AND `id_order` NOT IN (' . implode(', ', $skip) . ')' : '')
            . ' ORDER BY `date_upd` ASC, `id_wajub_payment` ASC LIMIT ' . (int) $limit,
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

    public function ordersWithOpenPayments(array $idShops, int $limit): array
    {
        $idShops = array_values(array_filter(array_map('intval', $idShops), static function (int $id): bool {
            return $id > 0;
        }));
        if ($idShops === [] || $limit <= 0) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_order`, MIN(`id_wajub_payment`) AS `first` FROM `' . $this->table() . '`'
            . ' WHERE `id_order` > 0 AND `id_shop` IN (' . implode(',', $idShops) . ')'
            . ' AND `state` IN (\'' . pSQL(PaymentRecord::ACTIVE) . '\', \'' . pSQL(PaymentRecord::UNCOUNTED) . '\')'
            . ' GROUP BY `id_order` ORDER BY `first` ASC LIMIT ' . (int) $limit,
            true,
            false
        );

        $orders = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $orders[] = (int) ($row['id_order'] ?? 0);
        }

        return array_values(array_filter($orders));
    }

    public function forCustomer(int $idCustomer): array
    {
        if ($idCustomer <= 0) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT * FROM `' . $this->table() . '` WHERE `id_customer` = ' . (int) $idCustomer
            . ' AND `id_order` > 0 ORDER BY `id_wajub_payment` ASC',
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

    public function forgetCustomer(int $idCustomer): int
    {
        if ($idCustomer <= 0) {
            return 0;
        }
        $db = \Db::getInstance();
        $db->execute('UPDATE `' . $this->table() . '` SET `id_customer` = 0 WHERE `id_customer` = ' . (int) $idCustomer, false);

        return (int) $db->Affected_Rows();
    }

    public function save(PaymentRecord $record): void
    {
        $now = date('Y-m-d H:i:s');
        $record->dateUpd = $now;
        if ($record->dateAdd === '') {
            $record->dateAdd = $now;
        }

        $values = [
            'payment_id' => pSQL($record->paymentId),
            'reference' => pSQL(mb_substr($record->reference, 0, 128)),
            'id_shop' => (int) $record->idShop,
            'id_cart' => (int) $record->idCart,
            'id_order' => (int) $record->idOrder,
            'id_customer' => (int) $record->idCustomer,
            'environment' => pSQL($record->environment),
            'method' => pSQL($record->method),
            'mode' => pSQL($record->mode),
            'state' => pSQL($record->state),
            'status' => pSQL(substr($record->status, 0, 32)),
            'currency' => pSQL(substr($record->currency, 0, 3)),
            'amount' => self::decimal($record->amount),
            'amount_paid' => self::decimal($record->amountPaid),
            'amount_refunded' => self::decimal($record->amountRefunded),
            'counted' => self::decimal($record->counted),
            'id_order_payment' => (int) $record->idOrderPayment,
            'channel' => pSQL(substr($record->channel, 0, 64)),
            'activation_hash' => pSQL($record->activationHash),
            'expires_at' => max(0, (int) $record->expiresAt),
            // JSON escaped so that pSQL() has nothing to strip.
            'metadata' => pSQL((string) json_encode($record->metadata, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)),
            'date_add' => pSQL($record->dateAdd),
            'date_upd' => pSQL($record->dateUpd),
        ];

        $db = \Db::getInstance();
        if ($record->id > 0) {
            $db->update(self::TABLE, $values, '`id_wajub_payment` = ' . (int) $record->id, 0, false, false);

            return;
        }
        $db->insert(self::TABLE, $values, false, false);
        $record->id = (int) $db->Insert_ID();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function record(array $row): PaymentRecord
    {
        $record = new PaymentRecord();
        $record->id = (int) ($row['id_wajub_payment'] ?? 0);
        $record->paymentId = (string) ($row['payment_id'] ?? '');
        $record->reference = (string) ($row['reference'] ?? '');
        $record->idShop = (int) ($row['id_shop'] ?? 0);
        $record->idCart = (int) ($row['id_cart'] ?? 0);
        $record->idOrder = (int) ($row['id_order'] ?? 0);
        $record->idCustomer = (int) ($row['id_customer'] ?? 0);
        $record->environment = (string) ($row['environment'] ?? '');
        $record->method = (string) ($row['method'] ?? '');
        $record->mode = (string) ($row['mode'] ?? '');
        $record->state = (string) ($row['state'] ?? PaymentRecord::PREPARED);
        $record->status = (string) ($row['status'] ?? '');
        $record->currency = (string) ($row['currency'] ?? '');
        $record->amount = (float) ($row['amount'] ?? 0);
        $record->amountPaid = (float) ($row['amount_paid'] ?? 0);
        $record->amountRefunded = (float) ($row['amount_refunded'] ?? 0);
        $record->counted = (float) ($row['counted'] ?? 0);
        $record->idOrderPayment = (int) ($row['id_order_payment'] ?? 0);
        $record->channel = (string) ($row['channel'] ?? '');
        $record->activationHash = (string) ($row['activation_hash'] ?? '');
        $record->expiresAt = (int) ($row['expires_at'] ?? 0);
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
        foreach (is_array($metadata) ? $metadata : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $record->metadata[$key] = (string) $value;
            }
        }
        $record->dateAdd = (string) ($row['date_add'] ?? '');
        $record->dateUpd = (string) ($row['date_upd'] ?? '');

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
