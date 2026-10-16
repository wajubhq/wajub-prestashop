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
 * Where the checkout's payment form and the order confirmation page ask how
 * an order's payment stands (Checkout\StatusAnswer): GET `id_order` and
 * `token` (the order's, which only its pages carry). Answers JSON. Reading
 * it settles a payment that went through, as the webhook would.
 */
class WajubStatusModuleFrontController extends ModuleFrontController
{
    use FrontAnswers;

    /** @var Wajub */
    public $module;

    /** @var bool */
    public $ajax = true;

    /** Seconds a check waits for the webhook or the payer's return to finish with the order. */
    private const LOCK_WAIT = 3;

    /**
     * @return void
     */
    public function postProcess()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $order = $idOrder > 0 ? new Order($idOrder) : null;
        if ($order === null || !Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            $this->answerJson(404, ['success' => false, 'data' => ['message' => '']]);
        }

        $services = $this->module->servicesFor((int) $order->id_shop);
        if (!Tokens::matches($services->tokens()->order((int) $order->id, (string) $order->secure_key), $this->line((string) Tools::getValue('token', ''), 64))) {
            $this->answerJson(403, ['success' => false, 'data' => ['message' => '']]);
        }

        $lock = $services->lock();
        $name = 'order:' . $services->orders()->leadId((int) $order->id);
        if (!$lock->acquire($name, self::LOCK_WAIT)) {
            // Being settled by another request: the next check says.
            $this->answerJson(200, ['success' => true, 'data' => ['status' => 'pending', 'processing' => true]]);
        }
        try {
            // Read under the lock: the webhook may have paid it meanwhile.
            $view = $services->orders()->find((int) $order->id);
            $answer = $view !== null ? $services->statusAnswer()->answer($view) : ['status' => 'pending', 'processing' => false];
        } catch (Throwable $e) {
            $services->logger()->error('Order status check failed', ['order' => (int) $order->id, 'error' => $e->getMessage()]);
            $answer = ['status' => 'pending', 'processing' => false];
        } finally {
            $lock->release($name);
        }

        $this->answerJson(200, ['success' => true, 'data' => $answer]);
    }
}
