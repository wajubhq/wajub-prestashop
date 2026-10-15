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

use Wajub\PrestaShop\Checkout\PrepareEndpoint;
use Wajub\PrestaShop\Checkout\PrestaShopCarts;
use Wajub\PrestaShop\Controller\FrontAnswers;

/**
 * Where the payment box of Mobile Money or Card asks for the payment its
 * form runs on, for the payer's cart (Checkout\PrepareEndpoint): POST
 * `kind`, `token` (the cart's checkout token, which the page carries), and
 * `renew`. Answers JSON: the payment's id, its session and its currency,
 * or why there is none.
 */
class WajubPrepareModuleFrontController extends ModuleFrontController
{
    use FrontAnswers;

    /** @var Wajub */
    public $module;

    /** @var bool */
    public $ajax = true;

    /**
     * @return void
     */
    public function postProcess()
    {
        if (!$this->isPost()) {
            $this->answerJson(405, ['success' => false, 'data' => ['code' => 'unavailable', 'message' => '']]);
        }

        $services = $this->module->services();
        $cart = $this->context->cart;
        $view = null;
        $expected = '';
        if ($cart instanceof Cart && Validate::isLoadedObject($cart) && (int) $cart->id_customer > 0 && !$cart->orderExists()) {
            $view = PrestaShopCarts::view($cart);
            $expected = $services->tokens()->checkout((int) $cart->id, (int) $cart->id_customer);
        }

        // A shop with heavy traffic, or a higher plan, raises them.
        /** @var mixed $limits what the hook's modules leave there */
        $limits = PrepareEndpoint::LIMITS;
        Hook::exec('actionWajubPrepareLimits', ['limits' => &$limits]);

        $answer = $services->prepareEndpoint()->answer([
            'kind' => $this->text('kind', 32),
            'token' => $this->text('token', 64),
            'renew' => $this->text('renew', 1),
        ], $view, $expected, $this->clientIp(), PrepareEndpoint::limits(is_array($limits) ? $limits : []));

        $this->answerJson($answer['status'], $answer['body'], $answer['status'] === 429 ? PrepareEndpoint::RETRY_AFTER : 0);
    }
}
