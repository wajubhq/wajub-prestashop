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
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return ['kernel.terminate' => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $module = \Module::getInstanceByName('wajub');
        if ($module instanceof \Wajub) {
            // An answer that is an error (a 500 Symfony's error handler drew):
            // PrestaShop may have stopped after the slip.
            $module->finishRefundForms($event->getResponse()->getStatusCode() < 500);
        }
    }
}
