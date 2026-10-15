<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Admin;

use Wajub\PrestaShop\Pairing\Pairing;
use Wajub\PrestaShop\Pairing\PairingException;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Webhook\WebhookRegistration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The settings page's buttons that answer without reloading it: "Connect
 * with Wajub" (start, poll, cancel), "Test connection", and "Register
 * automatically". The controller checks the employee's rights and the
 * page's token first; each answer is `success` and `data`, as the page's
 * scripts read it, with the HTTP status to send.
 *
 * A failure of Wajub is answered 200 with `success` false: a proxy or the
 * host would put its own page in place of a 5xx, and the merchant would
 * lose the words.
 */
final class AjaxActions
{
    public const ACTIONS = ['pairing_start', 'pairing_poll', 'pairing_cancel', 'test_connection', 'register_webhooks', 'check_order', 'refresh_order'];

    /** @var Services */
    private $services;

    /** @var (callable(int): Services)|null */
    private $servicesFor;

    /**
     * @param (callable(int): Services)|null $servicesFor the services of an order's shop ("Check status" on the order page)
     */
    public function __construct(Services $services, ?callable $servicesFor = null)
    {
        $this->services = $services;
        $this->servicesFor = $servicesFor;
    }

    /**
     * @param array<string, mixed> $posted
     *
     * @return array{status: int, body: array{success: bool, data: array<string, mixed>}}
     */
    public function handle(string $action, array $posted, int $employeeId): array
    {
        switch ($action) {
            case 'pairing_start':
                return $this->pairingStart($posted, $employeeId);
            case 'pairing_poll':
                return self::success($this->services->pairing()->poll($employeeId, self::code($posted)));
            case 'pairing_cancel':
                return self::success($this->services->pairing()->cancelShown($employeeId, self::code($posted)));
            case 'test_connection':
                return $this->testConnection();
            case 'register_webhooks':
                return $this->registerWebhooks($posted);
            case 'check_order':
                return $this->checkOrder($posted);
            case 'refresh_order':
                return $this->refreshOrder($posted);
            default:
                return self::failure(400, ['message' => $this->services->translator()->trans('Unknown action.', [], 'Modules.Wajub.Admin')]);
        }
    }

    /**
     * @param array<string, mixed> $posted
     *
     * @return array{status: int, body: array{success: bool, data: array<string, mixed>}}
     */
    private function pairingStart(array $posted, int $employeeId): array
    {
        $environment = is_string($posted['environment'] ?? null) ? $posted['environment'] : '';
        try {
            return self::success($this->services->pairing()->start($environment, $employeeId));
        } catch (PairingException $e) {
            return self::failure($e->httpStatus >= 500 ? 200 : $e->httpStatus, ['code' => $e->reason, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @return array{status: int, body: array{success: bool, data: array<string, mixed>}}
     */
    private function testConnection(): array
    {
        $status = $this->services->connectionStatus();
        $state = $status->refresh();

        return self::success($status->describe($state) + ['status' => $state['status']]);
    }

    /**
     * @param array<string, mixed> $posted
     *
     * @return array{status: int, body: array{success: bool, data: array<string, mixed>}}
     */
    private function registerWebhooks(array $posted): array
    {
        $rotate = in_array($posted['rotate'] ?? null, ['1', 1, true], true);
        $results = $this->services->webhookRegistration()->registerAll($rotate);
        if ($results === []) {
            return self::failure(400, ['message' => $this->services->translator()->trans('Add a secret key first: the webhook is registered with it.', [], 'Modules.Wajub.Admin')]);
        }

        $settings = $this->services->settings();
        $pairing = $this->services->pairing();
        $view = [];
        $secrets = [];
        foreach ($results as $environment => $result) {
            $ok = in_array($result['status'], WebhookRegistration::WORKING, true);
            $view[] = [
                'environment' => $environment,
                'label' => $pairing->environmentLabel($environment),
                'status' => $result['status'],
                'ok' => $ok,
                'manual' => $result['status'] === 'manual',
                'message' => $result['message'],
            ];
            // Masked, as the field shows a saved secret: never the secret.
            $saved = $settings->webhookSecret($environment);
            if ($ok && $saved !== '') {
                $secrets[$environment] = Keys::mask($saved);
            }
        }

        return self::success(['results' => $view, 'secrets' => $secrets]);
    }

    /**
     * "Check status" of the order page's Wajub panel, with the services of
     * the order's shop.
     *
     * @param array<string, mixed> $posted
     *
     * @return array{status: int, body: array{success: bool, data: array<string, mixed>}}
     */
    private function checkOrder(array $posted): array
    {
        $panel = $this->panelOf($posted);
        if ($panel === null) {
            return self::failure(400, ['message' => $this->services->translator()->trans('This order has no Wajub payment.', [], 'Modules.Wajub.Admin')]);
        }
        $result = $panel->check((int) $posted['id_order']);
        $data = ['message' => $result['message'], 'tone' => $result['tone'], 'reload' => $result['reload']];

        return $result['ok'] ? self::success($data) : self::failure(200, $data);
    }

    /**
     * The order page's panel, once loaded: the order's payments that could
     * not count are read again (OrderPanel::refresh()).
     *
     * @param array<string, mixed> $posted
     *
     * @return array{status: int, body: array{success: bool, data: array<string, mixed>}}
     */
    private function refreshOrder(array $posted): array
    {
        $panel = $this->panelOf($posted);
        if ($panel === null) {
            return self::failure(400, ['message' => '']);
        }

        return self::success($panel->refresh((int) $posted['id_order']));
    }

    /**
     * The panel of the order posted, with the services of the order's shop.
     *
     * @param array<string, mixed> $posted
     */
    private function panelOf(array $posted): ?OrderPanel
    {
        $idOrder = (int) ($posted['id_order'] ?? 0);
        $order = $idOrder > 0 ? new \Order($idOrder) : null;
        if ($order === null || !\Validate::isLoadedObject($order)) {
            return null;
        }

        return new OrderPanel($this->servicesFor !== null ? ($this->servicesFor)((int) $order->id_shop) : $this->services);
    }

    /**
     * The pairing code the page shows, in upper case, or '' for anything
     * that is not one.
     *
     * @param array<string, mixed> $posted
     */
    private static function code(array $posted): string
    {
        $code = is_string($posted['code'] ?? null) ? strtoupper(trim($posted['code'])) : '';

        return preg_match(Pairing::CODE_PATTERN, $code) === 1 ? $code : '';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{status: int, body: array{success: bool, data: array<string, mixed>}}
     */
    private static function success(array $data): array
    {
        return ['status' => 200, 'body' => ['success' => true, 'data' => $data]];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{status: int, body: array{success: bool, data: array<string, mixed>}}
     */
    public static function failure(int $status, array $data): array
    {
        return ['status' => $status, 'body' => ['success' => false, 'data' => $data]];
    }
}
