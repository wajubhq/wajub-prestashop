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

use Wajub\PrestaShop\Checkout\Tokens;
use Wajub\PrestaShop\Controller\FrontAnswers;

/**
 * Where Wajub sends the payer back from its hosted page (the payment's
 * `callback`): `id_order` and `token`, signed for the order, to which Wajub
 * adds what it likes, which is never read. What the payment became is read
 * from Wajub (Checkout\ReturnFlow), then the payer goes to the order's
 * confirmation page, or back to the checkout, with their cart, when the
 * payment ended unpaid.
 */
class WajubReturnModuleFrontController extends ModuleFrontController
{
    use FrontAnswers;

    /** @var Wajub */
    public $module;

    /** Seconds the return waits for the webhook to finish with the order. */
    private const LOCK_WAIT = 10;

    /**
     * @return void
     */
    public function postProcess()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $order = $idOrder > 0 ? new Order($idOrder) : null;
        if ($order === null || !Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            Tools::redirect($this->context->link->getPageLink('order'));
        }

        $services = $this->module->servicesFor((int) $order->id_shop);
        if (!Tokens::matches($services->tokens()->order((int) $order->id, (string) $order->secure_key), $this->line((string) Tools::getValue('token', ''), 64))) {
            Tools::redirect($this->context->link->getPageLink('order'));
        }

        $lock = $services->lock();
        $name = 'order:' . $services->orders()->leadId((int) $order->id);
        $where = ['url' => $this->context->link->getPageLink('order'), 'message' => '', 'tone' => 'info'];
        if ($lock->acquire($name, self::LOCK_WAIT)) {
            try {
                // Read under the lock: the webhook may have settled it, or
                // another tab given it back, while this request waited.
                $view = $services->orders()->find((int) $order->id);
                if ($view !== null) {
                    $where['url'] = $services->storefront()->confirmation($view);
                    $where = $services->returnFlow()->handle($view);
                }
            } catch (Throwable $e) {
                // The confirmation page follows the payment: the webhook settles it.
                $services->logger()->error('Payer return failed', ['order' => (int) $order->id, 'error' => $e->getMessage()]);
            } finally {
                $lock->release($name);
            }
        } else {
            $view = $services->orders()->find((int) $order->id);
            if ($view !== null) {
                $where['url'] = $services->storefront()->confirmation($view);
            }
        }

        if ($where['message'] !== '') {
            if ($where['tone'] === 'error') {
                $this->errors[] = $where['message'];
            } else {
                $this->info[] = $where['message'];
            }
        }
        $this->redirectWithNotifications($where['url']);
    }
}
