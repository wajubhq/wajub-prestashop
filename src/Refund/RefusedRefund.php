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

use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A refund of the order page Wajub refused, carried to PrestaShop in the
 * form's own data, so that PrestaShop makes no credit slip of it and shows
 * the merchant why.
 *
 * Why not an exception from the hook: PrestaShop 8 swallows what a module's
 * hook throws when it is not in debug mode (Hook::callHookOn() catches every
 * Exception, and only rethrows it, wrapped, in debug mode), and goes on to
 * make the slip; PrestaShop 9 lets a ModuleErrorException through. The
 * hook (actionBeforeUpdateCancelProductFormHandler) is handed the form's
 * data by reference, just before PrestaShop's handler builds its command
 * from it (PartialRefundFormDataHandler, StandardRefundFormDataHandler,
 * ReturnProductFormDataHandler, the same from 8.1 to 9): the products to
 * refund become this refusal, which the handler reads first thing, and
 * which throws its ModuleErrorException there, out of every hook. The form's
 * controller catches it, as it catches PrestaShop's own refusals, and shows
 * its message as it is (getErrorMessageForException()): nothing is made,
 * no stock moves, no slip, in production as in debug mode, on 8 as on 9.
 * The module runs last on the hook (Wajub::keepLastOnRefundForms()), so
 * that no other module's hook finds this in place of the products.
 *
 * @implements \IteratorAggregate<int, object>
 */
final class RefusedRefund implements \IteratorAggregate
{
    /** @var string */
    private $message;

    private function __construct(string $message)
    {
        $this->message = $message;
    }

    /**
     * Refuses the refund the hook's `$params` are about: true when its form
     * data carries the refusal now.
     *
     * @param array<string, mixed> $params the hook's, whose `form_data` is the form's data, by reference
     */
    public static function refuse(array &$params, string $message): bool
    {
        if (!isset($params['form_data']) || !is_array($params['form_data'])) {
            return false;
        }
        $params['form_data']['products'] = new self($message);

        return true;
    }

    /**
     * Whether the form data carries a refusal already.
     *
     * @param array<string, mixed> $data the form's data
     */
    public static function in(array $data): bool
    {
        return ($data['products'] ?? null) instanceof self;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * What PrestaShop's handler does first with the products to refund.
     *
     * @return \Traversable<int, object>
     *
     * @throws ModuleErrorException always: the refund is refused
     */
    public function getIterator(): \Traversable
    {
        throw new ModuleErrorException($this->message);
    }
}
