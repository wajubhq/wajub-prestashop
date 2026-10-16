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

/**
 * The address the merchant's scheduler calls (a cron job of the host, every
 * fifteen minutes or so), as the settings page shows it, with its token:
 * index.php?fc=module&module=wajub&controller=cron&token=…. One run of
 * Order\Cleanup: the payments that could not count read again, the orders
 * the payers left cancelled after the configured delay; then the e-mails of
 * keys that expire (Connection\KeyReminders). PrestaShop's Cron tasks
 * manager runs the same every hour without it (Wajub::hookActionCronJob()).
 * Each run is noted (Order\TaskLog), for the back office to say when the
 * task never runs, or no longer does.
 *
 * One address for every shop of a multistore: each order is handled with
 * the settings of its own shop (its delay, its key), whichever shop's
 * address the scheduler calls.
 *
 * A scheduler, not a visitor: no page is drawn, no cookie is written, and
 * maintenance mode does not turn it away. A wrong token is answered 403 and
 * does nothing.
 */
class WajubCronModuleFrontController extends ModuleFrontController
{
    /** @var Wajub */
    public $module;

    /** @var bool */
    public $ajax = true;

    /**
     * The shop in maintenance still settles its orders.
     *
     * @return void
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * A scheduler is in no country the shop restricts.
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
        $services = $this->module->services();
        $token = (string) Tools::getValue('token', '');
        if (!Tokens::matches($services->tokens()->cron(), substr(trim($token), 0, 64))) {
            $this->respond(403, ['ran' => false]);
        }

        try {
            $done = $this->module->runScheduledTask();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Wajub: the scheduled task failed: ' . $e->getMessage(), 3, null, 'Wajub', 0, true);
            $done = null;
        }
        if ($done === null) {
            $this->respond(500, ['ran' => false]);

            return;
        }

        $this->respond(200, ['ran' => true, 'recounted' => $done['recounted'], 'cancelled' => count($done['cancelled']), 'kept' => count($done['kept']), 'reminded' => $done['reminded']]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        $this->ajaxRender((string) json_encode($body, JSON_UNESCAPED_SLASHES));
        exit;
    }
}
