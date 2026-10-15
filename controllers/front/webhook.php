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

use Wajub\PrestaShop\Controller\FrontAnswers;

/**
 * Where Wajub posts its webhooks for this shop:
 * index.php?fc=module&module=wajub&controller=webhook, or its friendly URL.
 *
 * The request is Wajub's server, not a visitor: no page is drawn, no cookie
 * is written, and neither maintenance mode nor a country restriction turns
 * it away (Wajub retries a delivery for a while, then gives up, and an order
 * paid meanwhile would never settle). What it may do is decided by the
 * signature alone (Webhook\Receiver): a delivery no saved signing secret
 * signed is answered 403 and changes nothing.
 *
 * In a multistore, the address names the shop, group or "All shops" whose
 * endpoint it is (`scope`, Wajub::webhookServices()): the delivery is
 * verified with that scope's signing secrets, and each order is then
 * settled with the services of its own shop.
 */
class WajubWebhookModuleFrontController extends ModuleFrontController
{
    use FrontAnswers;

    /** @var Wajub */
    public $module;

    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $ssl = true;

    /**
     * The shop in maintenance still hears from Wajub: deliveries are
     * verified, and nothing a visitor could reach is shown.
     *
     * @return void
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Wajub's servers are in no country the shop restricts.
     *
     * @return void
     */
    protected function displayRestrictedCountryPage()
    {
    }

    /**
     * @return void
     */
    public function postProcess()
    {
        if ($this->context->cookie instanceof Cookie) {
            $this->context->cookie->disallowWriting();
        }

        if (!$this->isPost()) {
            $this->respond(405, ['received' => false], ['Allow: POST']);
        }

        // The body as sent, byte for byte: the signature is computed over
        // these bytes, and nothing in them is read before it is verified.
        $raw = (string) Tools::file_get_contents('php://input');
        $signature = $this->header('X-Wajub-Signature', 200);
        $timestamp = $this->header('X-Wajub-Timestamp', 32);
        $scope = Tools::getValue('scope', '');

        try {
            $services = $this->module->webhookServices(is_string($scope) ? substr($scope, 0, 16) : '');
            $answer = $services->receiver()->receive($raw, $signature, $timestamp);
        } catch (Throwable $e) {
            // A failure of the shop (its database, most often): Wajub sends
            // the event again later.
            PrestaShopLogger::addLog('Wajub: webhook failed: ' . $e->getMessage(), 3, null, 'Wajub', 0, true);
            $answer = ['status' => 500, 'body' => ['received' => false, 'retry' => true]];
        }

        $this->respond($answer['status'], $answer['body']);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $headers
     */
    private function respond(int $status, array $body, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        foreach ($headers as $header) {
            header($header);
        }
        $this->ajaxRender((string) json_encode($body, JSON_UNESCAPED_SLASHES));
        exit;
    }
}
