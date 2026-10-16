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

use Wajub\PrestaShop\Checkout\PrestaShopCarts;
use Wajub\PrestaShop\Checkout\Tokens;
use Wajub\PrestaShop\Controller\FrontAnswers;
use Wajub\PrestaShop\Support\CheckoutLocale;

/**
 * "Pay the rest": the payer pays what is left of an order part of which
 * is paid, from its confirmation page or their account's order page (POST
 * `id_order` and `token`, signed for the order). A payment on Wajub's hosted
 * page for what is left, once the order's open payments are settled
 * (Checkout\Starter::rest()): the plan paid in instalments taken up again or
 * closed, never two payments open at once. The payer goes to Wajub's page,
 * or back to the order's confirmation page with what to do.
 */
class WajubPayModuleFrontController extends ModuleFrontController
{
    use FrontAnswers;

    /** @var Wajub */
    public $module;

    /** Seconds the request waits for the webhook, or another tab, to finish with the order. */
    private const LOCK_WAIT = 10;

    /**
     * @return void
     */
    public function postProcess()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $order = $idOrder > 0 ? new Order($idOrder) : null;
        $home = $this->context->link->getPageLink('history', true);
        if (!$this->isPost() || $order === null || !Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            Tools::redirect($home);
        }

        $services = $this->module->servicesFor((int) $order->id_shop);
        if (!Tokens::matches($services->tokens()->order((int) $order->id, (string) $order->secure_key), $this->line((string) Tools::getValue('token', ''), 64))) {
            Tools::redirect($home);
        }
        $t = $services->translator();
        $busy = $t->trans('We could not check your payment with Wajub. Please try again in a moment.', [], 'Modules.Wajub.Shop');

        $lock = $services->lock();
        $name = 'order:' . $services->orders()->leadId((int) $order->id);
        $view = $services->orders()->find((int) $order->id);
        $confirmation = $view !== null ? $services->storefront()->confirmation($view) : $home;
        if (!$lock->acquire($name, self::LOCK_WAIT)) {
            $this->errors[] = $busy;
            $this->redirectWithNotifications($confirmation);
        }
        try {
            // Read under the lock: the webhook may have paid it meanwhile.
            $view = $services->orders()->find((int) $order->id);
            $cart = $view !== null ? PrestaShopCarts::view(new Cart($view->idCart)) : null;
            $answer = $view !== null && $cart !== null
                ? $services->starter()->rest($view, $cart, CheckoutLocale::of((string) $this->context->language->iso_code))
                : ['ok' => false, 'data' => ['code' => 'busy', 'message' => $busy]];
        } catch (Throwable $e) {
            $services->logger()->error('Paying the rest of an order failed', ['order' => (int) $order->id, 'error' => $e->getMessage()]);
            $answer = ['ok' => false, 'data' => ['code' => 'busy', 'message' => $busy]];
        } finally {
            $lock->release($name);
        }

        $data = $answer['data'];
        if ($answer['ok'] && is_string($data['redirect'] ?? null) && $data['redirect'] !== '') {
            Tools::redirect($data['redirect']);
        }
        $message = (string) ($data['message'] ?? '');
        if ($message !== '') {
            $this->errors[] = $message;
        }
        $this->redirectWithNotifications((string) ($data['redirect'] ?? '') !== '' ? (string) $data['redirect'] : $confirmation);
    }
}
