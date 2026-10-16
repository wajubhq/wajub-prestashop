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
 * Where the shop's pages ask it to read again the payments that could not
 * count (Order\Recounts): once one is due, a few minutes after its webhook
 * or its payer's return met Wajub not answering, the pages of the
 * storefront and of the back office post here from the visitor's browser
 * once they have loaded (views/js/recount.js), until the payments due are
 * read. What PrestaShop has instead of WP-Cron: no page waits for Wajub,
 * and the scheduled task, which reads them too, need not run for them.
 *
 * POST, no secret: nothing is read before a payment is due, one run at a
 * time, a few payments per run, each read again once per due time, however
 * many ask. Answers 204, nothing else: what it did is not the visitor's
 * business. No page is drawn, no cookie is written, and neither
 * maintenance mode nor a restricted country turns it away.
 */
class WajubRecountModuleFrontController extends ModuleFrontController
{
    use FrontAnswers;

    /** @var Wajub */
    public $module;

    /** @var bool */
    public $ajax = true;

    /**
     * The shop in maintenance still counts its payments.
     *
     * @return void
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * A visitor from a country the shop restricts loads its pages all the same.
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
        // A link followed, or a page prefetched, reads nothing.
        if ($this->isPost()) {
            try {
                $this->module->runRecounts();
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('Wajub: the payments due could not be read again: ' . $e->getMessage(), 3, null, 'Wajub', 0, true);
            }
        }
        http_response_code(204);
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        exit;
    }
}
