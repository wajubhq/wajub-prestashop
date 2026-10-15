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

use Wajub\PrestaShop\Checkout\Release;
use Wajub\PrestaShop\Checkout\Tokens;
use Wajub\PrestaShop\Controller\FrontAnswers;

/**
 * The payer leaves the payment of an order placed at checkout, to pay
 * another way or to try again (Checkout\Release): POST `id_order`, `token`
 * (the order's) and `reason` (switch, cancelled, expired, failed). The order
 * is given back as a cart, unless its payment went through or may still be
 * charged. The checkout's script asks with `ajax=1` and reads JSON; the
 * order confirmation page's form is answered with a redirect.
 */
class WajubReleaseModuleFrontController extends ModuleFrontController
{
    use FrontAnswers;

    /** @var Wajub */
    public $module;

    /** Seconds the request waits for the webhook to finish with the order. */
    private const LOCK_WAIT = 10;

    /** The reasons the scripts give. */
    private const REASONS = ['switch', 'cancelled', 'expired', 'failed'];

    /**
     * @return void
     */
    public function postProcess()
    {
        $json = (string) Tools::getValue('ajax') === '1';
        $idOrder = (int) Tools::getValue('id_order');
        $order = $idOrder > 0 ? new Order($idOrder) : null;
        if (!$this->isPost() || $order === null || !Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            $this->leaveWith($json, ['to' => Release::TO_CHECKOUT, 'url' => $this->context->link->getPageLink('order'), 'message' => '', 'tone' => 'info']);
        }

        $services = $this->module->servicesFor((int) $order->id_shop);
        if (!Tokens::matches($services->tokens()->order((int) $order->id, (string) $order->secure_key), $this->text('token', 64))) {
            $this->leaveWith($json, ['to' => Release::TO_CHECKOUT, 'url' => $this->context->link->getPageLink('order'), 'message' => '', 'tone' => 'info']);
        }
        $reason = $this->text('reason', 16);
        $reason = in_array($reason, self::REASONS, true) ? $reason : 'switch';

        $lock = $services->lock();
        $name = 'order:' . (int) $order->id;
        $busy = $services->translator()->trans('We could not check your payment with Wajub. Please try again in a moment.', [], 'Modules.Wajub.Shop');
        if (!$lock->acquire($name, self::LOCK_WAIT)) {
            $this->leaveWith($json, ['to' => Release::STAY, 'url' => '', 'message' => $busy, 'tone' => 'error']);
        }
        $view = null;
        try {
            // Read under the lock: another tab, or the webhook, may have
            // given it back or paid it while this request waited.
            $view = $services->orders()->find((int) $order->id);
            $left = $view !== null ? $services->release()->leave($view, $reason) : ['to' => Release::STAY, 'url' => '', 'message' => $busy, 'tone' => 'error'];
        } catch (Throwable $e) {
            $services->logger()->error('Leaving the payment failed', ['order' => (int) $order->id, 'error' => $e->getMessage()]);
            $left = ['to' => Release::STAY, 'url' => '', 'message' => $busy, 'tone' => 'error'];
        } finally {
            $lock->release($name);
        }

        if ($left['to'] === Release::STAY && !$json) {
            // A page that cannot stay: the order's confirmation page, which
            // follows the payment.
            $left['url'] = $view !== null ? $services->storefront()->confirmation($view) : '';
        }
        $this->leaveWith($json, $left);
    }

    /**
     * @param array{to: string, url: string, message: string, tone: string} $left
     */
    private function leaveWith(bool $json, array $left): void
    {
        if ($left['message'] !== '') {
            if ($left['tone'] === 'info') {
                $this->info[] = $left['message'];
            } else {
                $this->errors[] = $left['message'];
            }
        }
        if ($json) {
            if ($left['to'] !== Release::STAY && $left['message'] !== '') {
                $this->keepNotifications();
            }
            $this->answerJson(200, ['success' => true, 'data' => $left]);
        }
        $this->redirectWithNotifications($left['url'] !== '' ? $left['url'] : $this->context->link->getPageLink('order'));
    }
}
