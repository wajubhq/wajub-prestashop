<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Refund;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The end of a request of the back office, for the refunds of the order
 * page's forms (Refund\PendingSlips): the forms are Symfony pages, whose
 * kernel says when the request ends, once the answer is sent
 * (kernel.terminate). What PrestaShop did after Wajub refunded (made the
 * slip, refused, stopped in another module's hook on an exception), what
 * Wajub refunded and no slip records yet is recorded then
 * (Wajub::finishRefundForms()).
 *
 * Unlike a PHP shutdown function, the kernel may never get there once a PHP
 * error (an Error, not an exception) leaves PrestaShop's controller, which
 * neither PrestaShop's hooks nor Symfony's kernel catch: it ends the kernel
 * only when Symfony's error handler is the one that answers for it, not
 * when PHP itself ends the request (another module's error reporting in
 * its place, say). What must not wait is recorded before: the slip
 * PrestaShop adds records the refund as it is saved (Wajub::hookActionObjectOrderSlipAddAfter()); a refund it never
 * made a slip of is recorded once Wajub's webhook says it went through
 * (RefundSync::webhook()), and the order's lock goes with the request's
 * database connection.
 *
 * Declared in config/admin/services.yml, which PrestaShop 8 and 9 load for
 * an installed module that is on. A request that sent no refund form does
 * nothing here.
 */
final class RefundFormsEnd implements EventSubscriberInterface
{
    /**
     * Whether the answer carries an error the page put aside for the next
     * page (a flash message), as the session held it before Symfony saved
     * it: once saved, it is closed, and never read again this request.
     *
     * @var bool
     */
    private $errorFlashed = false;

    /**
     * @return array<string, string|array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Before Symfony's session listener saves the session (-1000).
        return ['kernel.response' => ['onResponse', -999], 'kernel.terminate' => 'onTerminate'];
    }

    /**
     * The order page catches another module's exception in
     * actionOrderSlipAdd (in debug mode, or one PrestaShop 9 always lets
     * through), says it in a flash message and redirects: a 302 that
     * stopped before the refunded quantities. The answer of the request
     * itself comes last, after those of its sub-requests.
     */
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession()) {
            $this->errorFlashed = false;

            return;
        }
        $session = $request->getSession();
        // Never started: nothing was put aside.
        $this->errorFlashed = $session->isStarted() && method_exists($session, 'getFlashBag') && $session->getFlashBag()->peek('error') !== [];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $module = \Module::getInstanceByName('wajub');
        if ($module instanceof \Wajub) {
            // Ended well: no 500 (Symfony's error handler), and no error
            // the order page put aside for the next page.
            $module->finishRefundForms($event->getResponse()->getStatusCode() < 500 && !$this->errorFlashed);
        }
    }
}
