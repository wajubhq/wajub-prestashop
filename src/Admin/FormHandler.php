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

use Wajub\PrestaShop\Connection\KeyManager;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What each form of the settings page saves, from what it posted. The
 * controller checks the employee's rights and the form's token first, and
 * hands over the posted values as they came: every value is read here as
 * the type it must be, and anything else is ignored.
 *
 * Each method answers notices for the merchant: ['type' => success | info |
 * warning | error, 'text' => …].
 */
final class FormHandler
{
    /**
     * The forms of the page: one per tab, and, for a shop or a group of a
     * multistore, the button that makes it use the connection of the
     * scope above again (`inherit`).
     */
    public const FORMS = ['connection', 'methods', 'appearance', 'webhooks', 'inherit'];

    /** How many orders the refusal of `inherit` names. */
    private const OPEN_ORDERS_SHOWN = 5;

    /** @var Services */
    private $services;

    public function __construct(Services $services)
    {
        $this->services = $services;
    }

    /**
     * @param array<string, mixed> $posted
     * @param list<int> $languageIds the shop's languages, for the titles and descriptions
     *
     * @return list<array{type: string, text: string}>
     */
    public function handle(string $form, array $posted, array $languageIds): array
    {
        switch ($form) {
            case 'connection':
                return $this->connection($posted);
            case 'methods':
                return $this->methods($posted, $languageIds);
            case 'appearance':
                return $this->appearance($posted);
            case 'webhooks':
                return $this->webhooks($posted);
            case 'inherit':
                return $this->inherit();
            default:
                return [];
        }
    }

    /**
     * The keys pasted or removed, then the environment: a key saved in the
     * same form counts, so a merchant can paste the live key and choose Live
     * in one save.
     *
     * @param array<string, mixed> $posted
     *
     * @return list<array{type: string, text: string}>
     */
    private function connection(array $posted): array
    {
        $keys = $this->services->keyManager();
        $settings = $this->services->settings();
        $t = $this->services->translator();
        $requested = self::choice($posted['environment'] ?? '', Settings::ENVIRONMENTS, $settings->environment());

        $notices = [];
        foreach (Settings::ENVIRONMENTS as $environment) {
            if (self::checked($posted['remove_' . $environment . '_key'] ?? null)) {
                $notices = array_merge($notices, $keys->removeKey($environment, $requested));

                continue;
            }
            $raw = $posted[$environment . '_key'] ?? '';
            if (is_string($raw) && trim($raw) !== '') {
                $notices = array_merge($notices, $keys->saveKey($environment, $raw)['notices']);
            }
        }

        $notices = array_merge($notices, $keys->switchEnvironment($requested));
        if ($this->services->environment()->liveKeyMissing()) {
            // A shop that just got a sandbox key of its own while it reads
            // Live: it no longer pays with the live key saved above it.
            $notices[] = KeyManager::notice('warning', $keys->liveKeyMissingMessage());
        }

        $debug = self::checked($posted['debug_log'] ?? null);
        if ($debug !== $settings->debugLog()) {
            $settings->setDebugLog($debug);
        }

        if ($notices === []) {
            $notices[] = KeyManager::notice('success', $t->trans('Settings saved.', [], 'Modules.Wajub.Admin'));
        }

        return $notices;
    }

    /**
     * Each payment option: on or off, its title and description in every
     * language (an empty field reads as the default of the page's
     * language), its logos, and how Mobile Money finds the operator.
     *
     * @param array<string, mixed> $posted
     * @param list<int> $languageIds
     *
     * @return list<array{type: string, text: string}>
     */
    private function methods(array $posted, array $languageIds): array
    {
        $settings = $this->services->settings();
        foreach (Methods::KINDS as $kind) {
            $settings->setMethodEnabled($kind, self::checked($posted[$kind . '_enabled'] ?? null));

            $titles = [];
            $descriptions = [];
            $hideDescription = self::checked($posted[$kind . '_no_description'] ?? null);
            $postedTitles = is_array($posted[$kind . '_title'] ?? null) ? $posted[$kind . '_title'] : [];
            $postedDescriptions = is_array($posted[$kind . '_description'] ?? null) ? $posted[$kind . '_description'] : [];
            foreach ($languageIds as $idLang) {
                $titles[$idLang] = Text::line($postedTitles[$idLang] ?? '', Settings::TITLE_MAX);
                $description = Text::lines($postedDescriptions[$idLang] ?? '', Settings::DESCRIPTION_MAX);
                // An empty field keeps the default, which follows the
                // language of the page; "no description" shows none.
                $descriptions[$idLang] = $hideDescription ? '' : ($description !== '' ? $description : null);
            }
            $settings->setMethodTexts($kind, $titles, $descriptions);

            $logos = is_array($posted[$kind . '_logos'] ?? null) ? array_values($posted[$kind . '_logos']) : [];
            $settings->setMethodLogos($kind, Methods::sanitizeLogos($logos, $kind));
        }
        $settings->setOperatorFromNumber(($posted['mobile_money_operator'] ?? '') === 'detect');
        $minutes = $posted['cancel_after'] ?? null;
        if (is_string($minutes) && preg_match('/^\s*\d{1,6}\s*$/', $minutes) === 1) {
            $settings->setCancelAfter((int) $minutes);
        }

        return [KeyManager::notice('success', $this->services->translator()->trans('Payment methods saved.', [], 'Modules.Wajub.Admin'))];
    }

    /**
     * @param array<string, mixed> $posted
     *
     * @return list<array{type: string, text: string}>
     */
    private function appearance(array $posted): array
    {
        $settings = $this->services->settings();
        $settings->setFormTheme(self::choice($posted['form_theme'] ?? '', Settings::THEMES, 'system'));
        $settings->setSecuredLine(self::checked($posted['secured_line'] ?? null));

        return [KeyManager::notice('success', $this->services->translator()->trans('Appearance saved.', [], 'Modules.Wajub.Admin'))];
    }

    /**
     * Signing secrets pasted by hand, for a webhook added in the Wajub
     * Dashboard: masked like a key, so an empty field keeps the saved one,
     * and a new one must be a signing secret of the field's environment.
     *
     * @param array<string, mixed> $posted
     *
     * @return list<array{type: string, text: string}>
     */
    private function webhooks(array $posted): array
    {
        $settings = $this->services->settings();
        $t = $this->services->translator();
        $notices = [];
        foreach (Settings::ENVIRONMENTS as $environment) {
            $live = $environment === Settings::LIVE;
            if (self::checked($posted['remove_' . $environment . '_secret'] ?? null)) {
                if ($settings->webhookSecret($environment) !== '' || $settings->webhookSecretUnreadable($environment)) {
                    $settings->setWebhookSecret($environment, '');
                    $notices[] = KeyManager::notice('warning', $live
                        ? $t->trans('Live signing secret removed. Live webhooks will be refused until a new one is saved.', [], 'Modules.Wajub.Admin')
                        : $t->trans('Sandbox signing secret removed. Sandbox webhooks will be refused until a new one is saved.', [], 'Modules.Wajub.Admin'));
                }

                continue;
            }

            $raw = $posted[$environment . '_secret'] ?? '';
            $secret = is_string($raw) ? Keys::normalize($raw) : '';
            if ($secret === '' || $secret === $settings->webhookSecret($environment)) {
                continue;
            }
            $problem = Keys::webhookSecretProblem($secret, $environment, $t);
            if ($problem !== '') {
                $notices[] = KeyManager::notice('error', ($live
                    ? $t->trans('Live signing secret not saved.', [], 'Modules.Wajub.Admin')
                    : $t->trans('Sandbox signing secret not saved.', [], 'Modules.Wajub.Admin')) . ' ' . $problem);

                continue;
            }
            $settings->setWebhookSecret($environment, $secret);
            $notices[] = KeyManager::notice('success', $live
                ? $t->trans('Live signing secret saved.', [], 'Modules.Wajub.Admin')
                : $t->trans('Sandbox signing secret saved.', [], 'Modules.Wajub.Admin'));
        }

        if ($notices === []) {
            $notices[] = KeyManager::notice('info', $t->trans('Nothing to save: paste a signing secret first.', [], 'Modules.Wajub.Admin'));
        }

        return $notices;
    }

    /**
     * A shop or a group of a multistore that connected on its own goes back
     * to the connection of the scope above (its group's, or all shops'):
     * its environment, keys, signing secrets and webhook endpoints are
     * forgotten here. Its key and its webhook endpoint stay in Wajub, where
     * the merchant deletes them.
     *
     * Not while one of its orders holds a payment made with its own key that
     * Wajub may still take, or holds money for: read with the key above,
     * which may be another business's, Wajub would not know it, and the
     * scheduled task would take it for gone and cancel the order the payer
     * may be paying. Refunds of the orders it was paid for are then made
     * from the Wajub Dashboard of the business that key belongs to.
     *
     * @return list<array{type: string, text: string}>
     */
    private function inherit(): array
    {
        $settings = $this->services->settings();
        $t = $this->services->translator();
        $scope = $settings->scope();
        if ($scope->isAllShops() || !$settings->hasOwnConnection()) {
            return [KeyManager::notice('info', $scope->isGroup()
                ? $t->trans('Nothing to change: this group has no connection of its own.', [], 'Modules.Wajub.Admin')
                : $t->trans('Nothing to change: this shop has no connection of its own.', [], 'Modules.Wajub.Admin'))];
        }
        if ($settings->keysSavedFor() === $scope->slug()) {
            $open = $this->services->payments()->ordersWithOpenPayments($this->services->shop()->shopIds(), self::OPEN_ORDERS_SHOWN);
            if ($open !== []) {
                $this->services->logger()->info('Own connection kept: payments under way', ['scope' => $scope->slug(), 'orders' => $open]);

                return [KeyManager::notice('error', $t->trans('The connection was not changed: Wajub payments made with this connection’s key are still under way for orders %orders%. Once they are paid, or the orders cancelled, use the connection saved above again.', ['%orders%' => implode(', ', $open)], 'Modules.Wajub.Admin'))];
            }
        }
        $settings->forgetOwnConnection();
        $this->services->logger()->info('Own connection forgotten', ['scope' => $scope->slug()]);

        return [KeyManager::notice('success', $scope->isGroup()
            ? $t->trans('The connection saved for this group is forgotten: it now uses the one saved above it. Its key and webhook stay in your Wajub Dashboard, where you can delete them, and its paid orders are refunded from there.', [], 'Modules.Wajub.Admin')
            : $t->trans('The connection saved for this shop is forgotten: it now uses the one saved above it. Its key and webhook stay in your Wajub Dashboard, where you can delete them, and its paid orders are refunded from there.', [], 'Modules.Wajub.Admin'))];
    }

    private static function checked(mixed $value): bool
    {
        return in_array($value, ['1', 'on', 'yes', 1, true], true);
    }

    /**
     * @param list<string> $allowed
     */
    private static function choice(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
