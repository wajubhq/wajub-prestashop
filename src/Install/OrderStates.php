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

use Wajub\PrestaShop\Settings\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The order states the module adds.
 *
 * - Awaiting Wajub payment: the order is created when the customer places
 *   it, before Wajub has a payment for it, and waits here until one counts.
 *   Not paid, not "logable" (no sale in the statistics yet), no e-mail: the
 *   customer is on the checkout's payment form at that moment.
 * - Partially paid with Wajub: payments counted toward the order, short of
 *   its total (an instalment payment, or one closed early). PrestaShop has
 *   no state for it, and its own states would lie either way: "Awaiting"
 *   hides money already received, so the order may be cancelled as
 *   abandoned; "Payment accepted" ships an order not fully paid. The
 *   customer pays the rest from their order, and the order list tells
 *   these orders apart.
 *
 * Paid in full, an order moves to PrestaShop's own "Payment accepted".
 *
 * Uninstalling never deletes a state an order went through: it is marked
 * deleted, so those orders keep their history and its name, and its id stays
 * in the configuration, so a reinstall brings that very state back, whatever
 * the merchant renamed it, and the orders in it are the module's again.
 */
final class OrderStates
{
    /** @var array<string, array{config: string, color: string, names: array<string, string>}> */
    public const STATES = [
        'awaiting' => [
            'config' => Settings::STATE_AWAITING,
            'color' => '#00B8DA',
            'names' => [
                'en' => 'Awaiting Wajub payment',
                'fr' => 'En attente de paiement Wajub',
            ],
        ],
        'partial' => [
            'config' => Settings::STATE_PARTIAL,
            'color' => '#E69500',
            'names' => [
                'en' => 'Partially paid with Wajub',
                'fr' => 'Partiellement payée avec Wajub',
            ],
        ],
    ];

    /** The icon PrestaShop shows beside a state, in img/os/<id>.gif. */
    private const ICON = 'views/img/order-state.gif';

    /**
     * The configuration keys of the states' ids, which uninstall() keeps
     * while a state stays for the orders that went through it.
     *
     * @return list<string>
     */
    public static function configKeys(): array
    {
        return array_column(self::STATES, 'config');
    }

    /**
     * Creates each state the shop does not have yet, and keeps its id (for
     * all shops, as PrestaShop keeps its own). A state the module made
     * before and that was marked deleted when it was uninstalled is brought
     * back rather than made twice: by the id kept for it, or, when that id
     * is lost, by one of the names the module gives it.
     */
    public static function install(string $moduleName, string $modulePath): bool
    {
        foreach (self::STATES as $definition) {
            $id = (int) \Configuration::getGlobalValue($definition['config']);
            $state = $id > 0 ? new \OrderState($id) : null;
            if ($state === null || !\Validate::isLoadedObject($state) || $state->module_name !== $moduleName) {
                $state = self::findDeleted($moduleName, array_values($definition['names'])) ?? new \OrderState();
            }

            $state->name = self::names($definition['names'], $state->name);
            $state->color = $definition['color'];
            $state->module_name = $moduleName;
            $state->send_email = false;
            $state->invoice = false;
            $state->logable = false;
            $state->paid = false;
            $state->shipped = false;
            $state->delivery = false;
            $state->hidden = false;
            $state->unremovable = true;
            $state->pdf_invoice = false;
            $state->pdf_delivery = false;
            $state->deleted = false;
            if (!$state->save()) {
                return false;
            }

            \Configuration::updateGlobalValue($definition['config'], (int) $state->id);
            self::copyIcon($modulePath, (int) $state->id);
        }

        return true;
    }

    /**
     * Deletes the states no order went through, with their ids, and marks
     * the others deleted: PrestaShop then offers them no more, the orders
     * that had them keep their history, and their ids stay for a reinstall.
     */
    public static function uninstall(): bool
    {
        $done = true;
        foreach (self::STATES as $definition) {
            $id = (int) \Configuration::getGlobalValue($definition['config']);
            $state = $id > 0 ? new \OrderState($id) : null;
            if ($state === null || !\Validate::isLoadedObject($state)) {
                \Configuration::deleteByName($definition['config']);

                continue;
            }

            $used = (bool) \Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order_state` = ' . $id
                . ' UNION SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` WHERE `current_state` = ' . $id,
                false
            );
            if ($used) {
                $state->deleted = true;
                $done = $state->save() && $done;
            } elseif ($state->delete()) {
                \Configuration::deleteByName($definition['config']);
            } else {
                $done = false;
            }
        }

        return $done;
    }

    /**
     * A state of the module's that an earlier uninstall marked deleted,
     * found by one of the names the module gives it, in any language: a
     * shop in French only has no English name, and the merchant may have
     * renamed it in one language but not another.
     *
     * @param list<string> $names
     */
    private static function findDeleted(string $moduleName, array $names): ?\OrderState
    {
        $id = (int) \Db::getInstance()->getValue(
            'SELECT os.`id_order_state` FROM `' . _DB_PREFIX_ . 'order_state` os'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON osl.`id_order_state` = os.`id_order_state`'
            . ' WHERE os.`module_name` = \'' . pSQL($moduleName) . '\' AND os.`deleted` = 1'
            . ' AND osl.`name` IN (\'' . implode('\', \'', array_map('pSQL', $names)) . '\')'
            . ' ORDER BY os.`id_order_state` DESC',
            false
        );

        return $id > 0 ? new \OrderState($id) : null;
    }

    /**
     * The state's name in every language of the shop: French for French,
     * English for every other language, keeping a name the merchant
     * changed since in International > Order states.
     *
     * @param array<string, string> $names by language iso code
     * @param array<int, string>|mixed $current
     *
     * @return array<int, string> by language id
     */
    private static function names(array $names, $current): array
    {
        $byLanguage = [];
        foreach (\Language::getLanguages(false) as $language) {
            $idLang = (int) $language['id_lang'];
            $kept = is_array($current) ? (string) ($current[$idLang] ?? '') : '';
            $byLanguage[$idLang] = $kept !== '' && !in_array($kept, $names, true)
                ? $kept
                : ($names[strtolower((string) $language['iso_code'])] ?? $names['en']);
        }

        return $byLanguage;
    }

    private static function copyIcon(string $modulePath, int $id): void
    {
        $source = rtrim($modulePath, '/') . '/' . self::ICON;
        $target = _PS_ORDER_STATE_IMG_DIR_ . $id . '.gif';
        if (is_file($source) && !is_file($target) && is_writable(_PS_ORDER_STATE_IMG_DIR_)) {
            \Tools::copy($source, $target);
        }
    }
}
