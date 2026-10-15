<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Hands a verified event to every handler of its type. An event no handler
 * takes is answered as received all the same: Wajub may send types the
 * module does not act on, and must not send them again.
 */
final class Dispatcher
{
    /** @var list<EventHandler> */
    private $handlers;

    /**
     * @param list<EventHandler> $handlers
     */
    public function __construct(array $handlers = [])
    {
        $this->handlers = $handlers;
    }

    /** Whether a handler acted on it. */
    public function dispatch(Event $event): bool
    {
        $handled = false;
        foreach ($this->handlers as $handler) {
            if ($handler->handles($event->type)) {
                $handler->handle($event);
                $handled = true;
            }
        }

        return $handled;
    }
}
