<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's own tables. PrestaShop's tables are never altered: what the
 * module keeps about an order, a cart or a refund is in its own rows, keyed
 * by PrestaShop's ids.
 *
 * - wajub_state: short-lived state with an expiry (Storage\DbStateStore):
 *   pairing sessions, locks, rate limits, cached answers of Wajub, the
 *   webhook's health.
 * - wajub_event: one row per webhook event received, unique by its Wajub id,
 *   so each is acted on once (Webhook\DbEventStore).
 * - wajub_payment: one row per Wajub payment the shop started, for a cart
 *   and, once placed, its order; what it collected and what of it counted
 *   toward the order (an OrderPayment). Filled from the payments lot on.
 * - wajub_refund: one row per refund, asked from the shop (a credit slip)
 *   or made in the Wajub Dashboard, by its Wajub id, or, while the shop's
 *   call for it lasts, by the key the shop sent it with (`request_key`);
 *   `order_unpaid` says the order had never been paid when the refund was
 *   first seen: the payer pays what it gave back again, and it is never a
 *   refund of the order (Refund\RefundSync).
 * - wajub_order_note: what the module tells the merchant about an order's
 *   payments (a payment short of the total, one in another currency, a
 *   second payment), shown in the order's Wajub panel. Unique by order and
 *   key: a note written once, whoever reads the payment first (the webhook
 *   or the payer's return), in one statement.
 *
 * Amounts are DECIMAL(20,6), as PrestaShop keeps its own.
 *
 * Uninstalling keeps wajub_payment and wajub_refund (KEPT): what Wajub took
 * and refunded for each order is the merchant's order history, and
 * PrestaShop's "Reset" is an uninstall then an install. A later install
 * finds them as an earlier version left them: a version that changes one
 * changes it in create() as well, not only in an upgrade script, which a
 * fresh install does not run.
 */
final class Schema
{
    /** @var list<string> */
    public const TABLES = ['wajub_state', 'wajub_event', 'wajub_payment', 'wajub_refund', 'wajub_order_note'];

    /** @var list<string> the tables an uninstall leaves */
    public const KEPT = ['wajub_payment', 'wajub_refund', 'wajub_order_note'];

    /**
     * @return array<string, string> table => CREATE TABLE statement, with the shop's prefix and engine
     */
    public static function statements(string $prefix, string $engine): array
    {
        $tail = ' ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return [
            'wajub_state' => 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'wajub_state` (
                `name` VARCHAR(191) NOT NULL,
                `value` MEDIUMTEXT NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`name`),
                KEY `expires_at` (`expires_at`)
            )' . $tail,

            'wajub_event' => 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'wajub_event` (
                `id_wajub_event` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_id` VARCHAR(80) NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `environment` VARCHAR(8) NOT NULL,
                `type` VARCHAR(64) NOT NULL,
                `object_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                `status` VARCHAR(16) NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
                `error` VARCHAR(255) NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_wajub_event`),
                UNIQUE KEY `event_id` (`event_id`),
                KEY `object_id` (`object_id`),
                KEY `date_add` (`date_add`)
            )' . $tail,

            'wajub_payment' => 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'wajub_payment` (
                `id_wajub_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `payment_id` VARCHAR(64) NOT NULL,
                `reference` VARCHAR(128) NOT NULL DEFAULT \'\',
                `id_shop` INT UNSIGNED NOT NULL,
                `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_customer` INT UNSIGNED NOT NULL DEFAULT 0,
                `environment` VARCHAR(8) NOT NULL,
                `method` VARCHAR(16) NOT NULL,
                `mode` VARCHAR(16) NOT NULL DEFAULT \'\',
                `state` VARCHAR(16) NOT NULL DEFAULT \'created\',
                `status` VARCHAR(32) NOT NULL DEFAULT \'pending\',
                `currency` CHAR(3) NOT NULL,
                `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `amount_paid` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `amount_refunded` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `counted` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `id_order_payment` INT UNSIGNED NOT NULL DEFAULT 0,
                `channel` VARCHAR(64) NOT NULL DEFAULT \'\',
                `activation_hash` CHAR(64) NOT NULL DEFAULT \'\',
                `expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `metadata` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_wajub_payment`),
                UNIQUE KEY `payment_id` (`payment_id`),
                KEY `id_order` (`id_order`),
                KEY `id_cart` (`id_cart`),
                KEY `reference` (`reference`),
                KEY `state_expires` (`state`, `expires_at`)
            )' . $tail,

            'wajub_refund' => 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'wajub_refund` (
                `id_wajub_refund` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `refund_id` VARCHAR(64) NULL,
                `request_key` VARCHAR(64) NULL,
                `payment_id` VARCHAR(64) NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_order_slip` INT UNSIGNED NOT NULL DEFAULT 0,
                `environment` VARCHAR(8) NOT NULL,
                `origin` VARCHAR(16) NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT \'pending\',
                `currency` CHAR(3) NOT NULL,
                `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `reason` VARCHAR(64) NOT NULL DEFAULT \'\',
                `order_unpaid` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_wajub_refund`),
                UNIQUE KEY `refund_id` (`refund_id`),
                UNIQUE KEY `request_key` (`request_key`),
                KEY `payment_id` (`payment_id`),
                KEY `id_order` (`id_order`)
            )' . $tail,

            'wajub_order_note' => 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'wajub_order_note` (
                `id_wajub_order_note` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `note_key` VARCHAR(64) NOT NULL,
                `message` TEXT NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_wajub_order_note`),
                UNIQUE KEY `order_note` (`id_order`, `note_key`)
            )' . $tail,
        ];
    }

    /**
     * The columns a version added to a table an uninstall keeps (KEPT): an
     * install finds that table as the version before left it, and CREATE
     * TABLE IF NOT EXISTS leaves it so.
     *
     * @return array<string, array<string, string>> table => column => its definition
     */
    public static function addedColumns(): array
    {
        return [
            'wajub_refund' => [
                'order_unpaid' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            ],
        ];
    }

    public static function create(): bool
    {
        $db = \Db::getInstance();
        foreach (self::statements(_DB_PREFIX_, _MYSQL_ENGINE_) as $statement) {
            if (!$db->execute($statement)) {
                return false;
            }
        }
        foreach (self::addedColumns() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $known = $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '` LIKE \'' . pSQL($column) . '\'', true, false);
                if (is_array($known) && $known === [] && !$db->execute('ALTER TABLE `' . _DB_PREFIX_ . $table . '` ADD COLUMN `' . $column . '` ' . $definition)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Drops the tables an uninstall does not keep. */
    public static function drop(): bool
    {
        $db = \Db::getInstance();
        $done = true;
        foreach (array_diff(self::TABLES, self::KEPT) as $table) {
            $done = $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`') && $done;
        }

        return $done;
    }
}
