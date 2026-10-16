<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Module\Exception {
    if (PHP_SAPI !== 'cli') {
        exit;
    }

    /** PrestaShop's: an error a module means the merchant to read as it is. */
    interface ModuleErrorInterface
    {
    }

    /** PrestaShop's (Core\Module\Exception\ModuleErrorException, 8.1 to 9). */
    class ModuleErrorException extends \Exception implements ModuleErrorInterface
    {
    }
}

namespace {
    use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorInterface;

    /*
     * The way PrestaShop handles a refund of the order page, step by step as
     * its code does from 8.1 to 9, for what a module's hook can do to it:
     *
     * - OrderController::partialRefundAction() (and the standard refund's, the
     *   return's) catches any Exception and shows
     *   getErrorMessageForException(): a ModuleErrorInterface's message as it
     *   is, anything else wrapped;
     * - FormHandler::handleFormUpdate() dispatches
     *   actionBeforeUpdateCancelProductFormHandler with the form's data by
     *   reference, then hands that data to the form's data handler;
     * - Hook::callHookOn() runs the module's hook: PrestaShop 8 catches every
     *   Exception, rethrows it wrapped (CoreException) in debug mode only, and
     *   swallows it otherwise; PrestaShop 9 rethrows a ModuleErrorInterface
     *   as it is;
     * - PartialRefundFormDataHandler::update() reads the products first
     *   (foreach … getOrderDetailId()), then has the slip made.
     *
     * `$hook` is the module's, given the hook's parameters. What the merchant
     * reads, and whether a slip was made.
     */
    final class FakeRefundForm
    {
        /**
         * @param callable(array<string, mixed>): void $hook
         * @param array<string, mixed> $data the form's data
         *
         * @return array{message: string, slip: bool}
         */
        public static function post(callable $hook, array $data, int $prestashop, bool $debug): array
        {
            $slip = false;
            try {
                // FormHandler::handleFormUpdate()
                $params = ['form_data' => &$data, 'id' => 42];
                try {
                    // Hook::callHookOn()
                    $hook($params);
                } catch (ModuleErrorInterface $e) {
                    if ($prestashop < 9) {
                        if ($debug) {
                            throw new RuntimeException('[CoreException code 0]: ' . $e->getMessage(), 0, $e);
                        }
                    } else {
                        throw $e;
                    }
                } catch (Exception $e) {
                    if ($debug) {
                        throw new RuntimeException('[CoreException code 0]: ' . $e->getMessage(), 0, $e);
                    }
                }
                // PartialRefundFormDataHandler::update()
                foreach ($data['products'] as $product) {
                    $product->getOrderDetailId();
                }
                $slip = true;

                return ['message' => 'A partial refund was successfully created.', 'slip' => $slip];
            } catch (Exception $e) {
                // OrderController: getErrorMessageForException()
                return ['message' => $e instanceof ModuleErrorInterface ? $e->getMessage() : 'An unexpected error occurred. ' . $e->getMessage(), 'slip' => $slip];
            }
        }
    }

    /** A product of the order page's form, as PrestaShop hands it (OrderProductForViewing). */
    final class FakeRefundedProduct
    {
        public function getOrderDetailId(): int
        {
            return 7;
        }
    }
}
