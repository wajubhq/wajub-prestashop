<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.1: the address the shop's pages post to so that a payment that could
 * not count is read again a few minutes later (controllers/front/recount.php),
 * and a webhook that listens to the events of payments paid in instalments
 * (`payment.split.*`) and of live refunds under way (`refund.processing`).
 *
 * - The recount controller's page (`meta`), which an install makes for each
 *   of the module's controllers (Module::installControllers()), for its
 *   friendly address.
 * - Each webhook endpoint a shop, group or all shops registered gets the
 *   events it lacks (Wajub::subscribeWebhookEvents()): nothing created,
 *   no secret changed. Wajub not answering, or an endpoint registered by
 *   hand, is no failure of the upgrade: the log says so, and "Configure
 *   automatically" in the Webhooks tab does the same at any time.
 * - The module runs last on the order page's refund forms' hook, and goes
 *   back there when another module hooks it later (the new hook
 *   actionModuleRegisterHookAfter, Wajub::keepLastOnRefundForms()): the
 *   refusal it hands PrestaShop in the form's data reaches PrestaShop alone.
 * - The back office's "Wajub · Sandbox" (the new hook displayBackOfficeTop,
 *   registered with Wajub::HOOKS below), and the end of request of the
 *   refund forms (config/admin/services.yml), which PrestaShop loads once
 *   it has rebuilt its cache after the update. The scheduled task is waited
 *   for from the first page of the back office that asks (Admin\Notices).
 * - The credit slip of a refund of the order page records what Wajub
 *   refunded as PrestaShop adds it (the new hook
 *   actionObjectOrderSlipAddAfter, registered with Wajub::HOOKS below),
 *   before another module's hook can stop PrestaShop.
 * - The orders a cart that ships in several packages became now go by the
 *   first of them (Order\PrestaShopOrders::find()): what 1.0.0 recorded
 *   under the last (its payments, their refunds, its notes) moves to the
 *   first, where the module reads it.
 *
 * @param Wajub $module
 *
 * @return bool
 */
function upgrade_module_1_0_1($module)
{
    $page = 'module-' . $module->name . '-recount';
    $existing = (int) Db::getInstance()->getValue('SELECT `id_meta` FROM `' . _DB_PREFIX_ . 'meta` WHERE `page` = \'' . pSQL($page) . '\'');
    if ($existing === 0) {
        $meta = new Meta();
        $meta->page = $page;
        $meta->configurable = 1;
        if (!$meta->save()) {
            return false;
        }
    }

    // The first order of each cart placed in several orders.
    $groups = '(SELECT `reference`, `id_cart`, MIN(`id_order`) AS `first` FROM `' . _DB_PREFIX_ . 'orders` GROUP BY `reference`, `id_cart` HAVING COUNT(*) > 1)';
    foreach (['wajub_payment' => 'UPDATE', 'wajub_refund' => 'UPDATE', 'wajub_order_note' => 'UPDATE IGNORE'] as $table => $update) {
        $moved = Db::getInstance()->execute(
            $update . ' `' . _DB_PREFIX_ . $table . '` t'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = t.`id_order`'
            . ' INNER JOIN ' . $groups . ' g ON g.`reference` = o.`reference` AND g.`id_cart` = o.`id_cart`'
            . ' SET t.`id_order` = g.`first` WHERE t.`id_order` <> g.`first`'
        );
        if (!$moved) {
            return false;
        }
    }

    // Last on the refund forms' hook, and kept there as other modules hook
    // it later (Wajub::keepLastOnRefundForms()).
    if (!$module->registerHook(Wajub::HOOKS)) {
        return false;
    }
    $module->keepLastOnRefundForms();

    try {
        foreach ($module->subscribeWebhookEvents() as $slug => $results) {
            foreach ($results as $environment => $result) {
                if ($result === 'error') {
                    PrestaShopLogger::addLog('Wajub: the webhook of ' . $slug . ' (' . $environment . ') could not be given its new events. Use "Configure automatically" in the Webhooks tab.', 2, null, 'Wajub', 0, true);
                }
            }
        }
    } catch (Throwable $e) {
        PrestaShopLogger::addLog('Wajub: the webhooks could not be given their new events: ' . $e->getMessage(), 2, null, 'Wajub', 0, true);
    }

    return true;
}
