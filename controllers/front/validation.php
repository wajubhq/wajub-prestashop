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

use Wajub\PrestaShop\Checkout\CartView;
use Wajub\PrestaShop\Checkout\CheckoutStrings;
use Wajub\PrestaShop\Checkout\PrestaShopCarts;
use Wajub\PrestaShop\Checkout\Release;
use Wajub\PrestaShop\Controller\FrontAnswers;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Support\CheckoutLocale;
use Wajub\PrestaShop\Support\CustomerMessage;

/**
 * "Place order" with a Wajub option: the order is created here, in
 * "Awaiting Wajub payment" (PaymentModule::validateOrder()), then paid.
 *
 * POST `kind` (mobile_money, card, other), `token` (the cart's checkout
 * token) and `cart`, and for the payment form `wajub_prepared`, the id of
 * the payment it holds:
 * - with the form (an AJAX request, `ajax=1`): that payment, prepared for the
 *   cart, is activated for the order, and the answer (JSON) tells the
 *   checkout's script to submit the form;
 * - without (Other payment methods, a form that could not load, a browser
 *   without JavaScript): a payment on Wajub's hosted page, and the payer is
 *   sent there.
 *
 * A double click, or "Place order" again after an answer that was lost,
 * places nothing twice: the cart's lock lets one request through at a time,
 * and a cart placed already answers for its order, sending the same
 * activation again (Wajub answers it as done). A form older than the cart's
 * payment places no order. A cart placed already and placed again with
 * another method (a checkout left open in another tab) starts no payment
 * beside the order's own: that one is settled first (Checkout\Starter).
 *
 * The cart's lock is held until the order's is taken, by when the order
 * exists: should the database hold one named lock only per connection
 * (MySQL before 5.7, which drops the first as the second is taken), a
 * second request of the cart finds the order placed, and waits for the
 * order's lock instead.
 *
 * PrestaShop e-mails the customer the order's confirmation as it is placed:
 * held until the order is paid (Order\HeldConfirmations), so a payment
 * declined, or left for another method, sends none.
 */
class WajubValidationModuleFrontController extends ModuleFrontController
{
    use FrontAnswers;

    /** @var Wajub */
    public $module;

    /** Seconds a second request of the same cart waits for the first. */
    private const LOCK_WAIT = 15;

    /** @var bool whether the payment form's script asks (`ajax=1`), which reads JSON */
    private $fromScript = false;

    /**
     * @return void
     */
    public function postProcess()
    {
        $this->fromScript = (string) Tools::getValue('ajax') === '1';
        if (!$this->isPost()) {
            Tools::redirect($this->context->link->getPageLink('order'));
        }

        $services = $this->module->services();
        $kind = $this->text('kind', 32);
        if (!Methods::isKind($kind) || !$this->module->active || !$services->settings()->methodEnabled($kind)) {
            $this->fail('unavailable', CheckoutStrings::fields('', $services->translator())['unavailable']);
        }
        if (!$services->environment()->isConfigured()) {
            $this->fail('not_configured', CustomerMessage::shopUnavailable($services->translator()));
        }

        $cart = $this->cart($services);
        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer) || (int) $cart->id_address_delivery === 0 || (int) $cart->id_address_invoice === 0 || !$this->authorized($cart)) {
            $this->fail('cart', CheckoutStrings::refreshed($services->translator()), $this->context->link->getPageLink('order'));
        }

        $lock = $services->lock();
        $name = 'cart:' . (int) $cart->id;
        if (!$lock->acquire($name, self::LOCK_WAIT)) {
            $this->fail('busy', $services->translator()->trans('Your order is being placed. Please wait a moment.', [], 'Modules.Wajub.Shop'));
        }
        try {
            $answer = $this->place($services, $cart, $customer, $kind);
        } finally {
            $lock->release($name);
        }

        $this->finish($services, $answer);
    }

    /**
     * The order of the cart, placed now or before, and its payment.
     *
     * @return array{ok: bool, data: array<string, mixed>, order?: int}
     */
    private function place(Services $services, Cart $cart, Customer $customer, string $kind): array
    {
        $posted = $this->text('wajub_prepared', 80);
        $fields = Methods::isFields($kind) && $posted !== '';
        $idOrder = (int) Order::getIdByCartId((int) $cart->id);
        $view = PrestaShopCarts::view($cart);
        if (!$view instanceof CartView || !$view->payable()) {
            return ['ok' => false, 'data' => ['code' => 'cart', 'message' => CheckoutStrings::refreshed($services->translator())]];
        }

        $slot = null;
        if ($fields) {
            if ($idOrder === 0) {
                // Before the order exists: a stale or refused payment places none.
                $preflight = $services->starter()->preflight($view, $kind, $posted);
                if (!isset($preflight['slot'])) {
                    return $preflight;
                }
                $slot = $preflight['slot'];
            } else {
                // Placed already (a double click, a lost answer): its own payment.
                $slot = $services->payments()->find($posted);
                if ($slot === null || $slot->idCart !== (int) $cart->id || ($slot->idOrder !== 0 && $slot->idOrder !== $idOrder) || $slot->mode !== PaymentRecord::MODE_FIELDS) {
                    return ['ok' => false, 'data' => ['code' => 'refreshed', 'message' => CheckoutStrings::refreshed($services->translator()), 'redirect' => $this->context->link->getPageLink('order')]];
                }
            }
        }

        if ($idOrder === 0) {
            // As PrestaShop's own "Place order" checks it: a product sold
            // out, or no longer sold, meanwhile.
            if ($cart->isAllProductsInStock() !== true || $cart->checkAllProductsAreStillAvailableInThisState() !== true || $cart->checkAllProductsHaveMinimalQuantities() !== true) {
                return ['ok' => false, 'data' => ['code' => 'cart', 'message' => '', 'redirect' => $this->context->link->getPageLink('cart', null, null, ['action' => 'show'])]];
            }
            // Other payment methods with nothing left beyond Mobile Money and
            // Card (the account changed since the page was drawn): said
            // before an order is placed, only to be cancelled.
            if (!Methods::isFields($kind) && $services->offer()->typesLeft($view->currency, $view->total, $view->country) === []) {
                return ['ok' => false, 'data' => ['code' => 'method_unavailable', 'message' => CheckoutStrings::fields($kind, $services->translator())['unavailable']]];
            }
            $title = $services->checkoutOptions()->title($kind, (int) $this->context->language->id);
            $this->module->placingOrder = true;
            try {
                $this->module->validateOrder(
                    (int) $cart->id,
                    $services->settings()->awaitingState(),
                    (float) $cart->getOrderTotal(true, Cart::BOTH),
                    $title,
                    null,
                    [],
                    (int) $cart->id_currency,
                    false,
                    $customer->secure_key
                );
            } finally {
                $this->module->placingOrder = false;
            }
            $idOrder = (int) $this->module->currentOrder;
        }

        // Read again by the Starter under the order's lock: paid, given
        // back as a cart, or holding another payment by then.
        $order = $services->orders()->find($idOrder);
        if ($order === null) {
            return ['ok' => false, 'data' => ['code' => 'start_failed', 'message' => CheckoutStrings::fields($kind, $services->translator())['startFailed']]];
        }
        $placed = PrestaShopCarts::view(new Cart($order->idCart)) ?? $view;
        $language = CheckoutLocale::of((string) $this->context->language->iso_code);

        $orderLock = 'order:' . $order->id;
        $lock = $services->lock();
        if (!$lock->acquire($orderLock, self::LOCK_WAIT)) {
            return ['ok' => false, 'data' => ['code' => 'busy', 'message' => $services->translator()->trans('Your order is being placed. Please wait a moment.', [], 'Modules.Wajub.Shop')], 'order' => $order->id];
        }
        try {
            // The order and the form's payment are read again there.
            $answer = $slot !== null
                ? $services->starter()->fields($order, $placed, $kind, $slot, $language)
                : $services->starter()->hosted($order, $placed, $kind, $language);
            if (!$answer['ok'] && !empty($answer['data']['leave'])) {
                // No payment can be taken for the order this way, and nothing
                // was charged: the order goes back to being a cart.
                $left = $services->release()->leave($order, (string) $answer['data']['code'] === 'refreshed' ? 'switch' : 'unavailable');
                if ($left['to'] === Release::TO_CHECKOUT) {
                    $answer['data']['redirect'] = $left['url'];
                } elseif ($left['to'] === Release::TO_CONFIRMATION) {
                    $answer['data']['redirect'] = $left['url'];
                    $answer['data']['message'] = '';
                }
            }
        } finally {
            $lock->release($orderLock);
        }

        return $answer + ['order' => $order->id];
    }

    /**
     * Answers the payment form's script (JSON), or takes the payer where
     * they go, with the message they read there.
     *
     * @param array{ok: bool, data: array<string, mixed>} $answer
     */
    private function finish(Services $services, array $answer): void
    {
        $data = $answer['data'];
        if ($answer['ok']) {
            if ($this->fromScript) {
                $this->answerJson(200, ['success' => true, 'data' => $data]);
            }
            Tools::redirect((string) ($data['redirect'] ?? $this->context->link->getPageLink('order')));
        }

        $message = (string) ($data['message'] ?? '');
        $redirect = (string) ($data['redirect'] ?? '');
        $this->fail((string) ($data['code'] ?? 'unavailable'), $message, $redirect);
    }

    /**
     * The payer cannot pay this way, or not yet: the script says why, or the
     * payer is taken back (to the checkout, unless `$redirect` says where)
     * with the message.
     */
    private function fail(string $code, string $message, string $redirect = ''): void
    {
        if ($message !== '') {
            $this->errors[] = $message;
        }
        if ($this->fromScript) {
            if ($redirect !== '' && $message !== '') {
                // Read on the page the script takes the payer to.
                $this->keepNotifications();
            }
            $this->answerJson(200, ['success' => false, 'data' => ['code' => $code, 'message' => $message, 'redirect' => $redirect]]);
        }

        $this->redirectWithNotifications($redirect !== '' ? $redirect : $this->context->link->getPageLink('order'));
    }

    /**
     * The payer's cart: the one of the session, or, once placed (a second
     * click, a retry after a lost answer: PrestaShop gives the session a new
     * cart as soon as one is placed), the one posted, when it is this
     * customer's and its token is right. Ends the request otherwise.
     */
    private function cart(Services $services): Cart
    {
        $posted = (int) Tools::getValue('cart');
        $token = $this->text('token', 64);
        $cart = $this->context->cart;
        if (!$cart instanceof Cart || !Validate::isLoadedObject($cart) || (int) $cart->id !== $posted) {
            $cart = new Cart($posted);
            $customerId = (int) $this->context->customer->id;
            if (!Validate::isLoadedObject($cart) || !$cart->orderExists() || $customerId === 0 || (int) $cart->id_customer !== $customerId) {
                $this->fail('refreshed', CheckoutStrings::refreshed($services->translator()), $this->context->link->getPageLink('order'));
            }
        }
        $expected = $services->tokens()->checkout((int) $cart->id, (int) $cart->id_customer);
        if (!hash_equals($expected, $token)) {
            $this->fail('refreshed', CheckoutStrings::refreshed($services->translator()), $this->context->link->getPageLink('order'));
        }

        return $cart;
    }

    /** Whether the module may take this cart's payment: on for the shop, its currency, its country and group. */
    private function authorized(Cart $cart): bool
    {
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                return $this->module->checkCurrency($cart);
            }
        }

        return false;
    }
}
