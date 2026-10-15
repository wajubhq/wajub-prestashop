<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Connection;

use Wajub\PrestaShop\Pairing\KeyOrigins;
use Wajub\PrestaShop\Pairing\Pairing;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Mailer;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The merchant is warned before a key "Connect with Wajub" created stops
 * working, as the WordPress plugin warns (Pairing::remindExpiring() and its
 * notices): from KeyOrigins::WARN_BEFORE (14 days) before its date, a notice
 * on every page of the back office for the key a shop pays with, and one
 * e-mail per key, to the shop's e-mail address, whichever environment it
 * is for.
 *
 * Every key of the PrestaShop is looked at, whatever the shop in context: in
 * a multistore, a shop, a group or "All shops" may have connected on its own
 * (each saves its key with how it came, Settings::keyOriginsEverywhere()).
 * The e-mail is sent once a key: the key's origin keeps when
 * (KeyOrigins::markReminded()), and a new pairing writes a new origin. A
 * copy of the shop, at another address than the one that paired the key,
 * sends none: the shop that paired it sends its own.
 *
 * A key is in use when a shop pays with it: in a multistore, a shop that
 * reads the key of all shops (or of its group) and uses that key's
 * environment, whatever environment the scope that saved it uses itself.
 *
 * Run by the module's scheduled task and when the back office draws its
 * notices, so that a shop without a scheduler gets its e-mail too.
 */
final class KeyReminders
{
    /** The template of the e-mail, in the module's mails/<language>/. */
    public const TEMPLATE = 'key_expiry';

    /** @var Settings */
    private $settings;

    /** @var callable(string): ?Services */
    private $servicesOf;

    /** @var Mailer */
    private $mailer;

    /** @var Logger */
    private $logger;

    /** @var list<string> */
    private $shops;

    /** @var callable(string, Translator): string */
    private $scopeName;

    /**
     * @param Settings $settings the settings of any scope: the keys of every scope are read through them
     * @param callable(string): ?Services $servicesOf the services of a scope, by its slug; null for a shop or group this PrestaShop no longer has
     * @param list<string> $shops in a multistore, every shop's slug ('s1', 's2'); empty otherwise
     * @param (callable(string, Translator): string)|null $scopeName what the shop selector calls a scope, in a language; '' without multistore
     */
    public function __construct(Settings $settings, callable $servicesOf, Mailer $mailer, Logger $logger, array $shops = [], ?callable $scopeName = null)
    {
        $this->settings = $settings;
        $this->servicesOf = $servicesOf;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->shops = $shops;
        $this->scopeName = $scopeName ?? static function (string $slug, Translator $t): string {
            return '';
        };
    }

    /**
     * Every paired key that expires within two weeks, or has expired, by
     * the scope that saved it: whether a shop pays with it (`in_use`, and
     * which: `shops`, their ids, in a multistore), and whether the e-mail
     * went.
     *
     * @return list<array{scope: string, environment: string, state: string, expires_at: int, in_use: bool, shops: list<int>, reminded_at: int, warning: string, services: Services}>
     */
    public function due(?int $now = null): array
    {
        $now = $now ?? time();
        $due = [];
        foreach ($this->settings->keyOriginsEverywhere() as $slug => $byEnvironment) {
            $services = ($this->servicesOf)((string) $slug);
            if ($services === null) {
                continue;
            }
            foreach (Settings::ENVIRONMENTS as $environment) {
                if (!isset($byEnvironment[$environment])) {
                    continue;
                }
                $origins = $services->keyOrigins();
                $state = $origins->expiryState($environment, $now);
                $origin = $origins->pairedKey($environment);
                if ($state === '' || $origin === null) {
                    continue;
                }
                $shops = $this->shopsPayingWith((string) $slug, $environment);
                $due[] = [
                    'scope' => (string) $slug,
                    'environment' => $environment,
                    'state' => $state,
                    'expires_at' => (int) $origin['expires_at'],
                    // Without multistore, the one shop is all shops.
                    'in_use' => $this->shops !== [] ? $shops !== [] : $services->environment()->isLive() === ($environment === Settings::LIVE),
                    'shops' => $shops,
                    'reminded_at' => $origin['reminded_at'],
                    'warning' => $origins->expiryWarning($environment, $state, (int) $origin['expires_at']),
                    'services' => $services,
                ];
            }
        }

        return $due;
    }

    /**
     * The keys of `due` the back office warns an employee of: those a shop
     * pays with, and, in a multistore, only when the employee works on one
     * of those shops (`worksOn`): the others neither see that shop nor
     * connect it.
     *
     * @param list<array{scope: string, environment: string, state: string, expires_at: int, in_use: bool, shops: list<int>, reminded_at: int, warning: string, services: Services}> $due due()
     * @param callable(int): bool $worksOn whether the employee works on a shop
     *
     * @return list<array{scope: string, environment: string, state: string, expires_at: int, in_use: bool, shops: list<int>, reminded_at: int, warning: string, services: Services}>
     */
    public static function shownTo(array $due, callable $worksOn): array
    {
        return array_values(array_filter($due, static function (array $key) use ($worksOn): bool {
            if (!$key['in_use']) {
                return false;
            }
            if ($key['shops'] === []) {
                return true;
            }
            foreach ($key['shops'] as $idShop) {
                if ($worksOn($idShop)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * The shops of a multistore that pay with the key `slug` saved for
     * `environment`: they read their keys from that scope
     * (Settings::keySavedFor()) and use that environment.
     *
     * @return list<int>
     */
    private function shopsPayingWith(string $slug, string $environment): array
    {
        $paying = [];
        foreach ($this->shops as $shop) {
            $services = ($this->servicesOf)($shop);
            if ($services === null || $services->settings()->keySavedFor($environment) !== $slug) {
                continue;
            }
            if ($services->environment()->isLive() === ($environment === Settings::LIVE)) {
                $paying[] = (int) substr($shop, 1);
            }
        }

        return $paying;
    }

    /**
     * Sends the e-mail of each key due that never had one. Returns how many
     * went.
     */
    public function remind(?int $now = null): int
    {
        $now = $now ?? time();
        $sent = 0;
        foreach ($this->due($now) as $key) {
            if ($key['reminded_at'] > 0) {
                continue;
            }
            $services = $key['services'];
            $environment = $key['environment'];
            $origin = $services->keyOrigins()->pairedKey($environment);
            if ($origin === null) {
                continue;
            }
            if ($origin['home'] !== '' && !hash_equals($origin['home'], Pairing::addressOf($services->shop()->baseUrl()))) {
                continue;
            }
            $idShop = $services->shop()->id();
            $to = $this->mailer->shopEmail($idShop);
            if ($to === '') {
                // Nobody to write to: the back office's notice still says it.
                continue;
            }

            $services->keyOrigins()->markReminded($environment, $now);
            $t = $this->mailer->translator($idShop);
            $words = new KeyOrigins($services->settings(), $t, $this->logger);
            // In a multistore, the scope the key is saved for, which the shop
            // selector must name before connecting again.
            $scope = ($this->scopeName)($key['scope'], $t);
            $done = $this->mailer->send($idShop, $to, $t->trans('Connect with Wajub again', [], 'Modules.Wajub.Admin'), self::TEMPLATE, [
                'message' => $words->expiryWarning($environment, $key['state'], $key['expires_at']),
                'where' => $scope !== ''
                    ? $t->trans('In your PrestaShop back office, choose “%scope%” in the shop selector, open Modules > Module Manager, then Configure under Wajub: “Connect with Wajub” is in the Connection tab.', ['%scope%' => $scope], 'Modules.Wajub.Admin')
                    : $t->trans('In your PrestaShop back office, open Modules > Module Manager, then Configure under Wajub: “Connect with Wajub” is in the Connection tab.', [], 'Modules.Wajub.Admin'),
                'advice' => $t->trans('When you approve in Wajub, choose “No expiry” for a shop whose checkout must not stop. Otherwise, the key lasts 90 days and you connect again.', [], 'Modules.Wajub.Admin'),
            ]);
            $this->logger->info($done ? 'Paired key expiry e-mail sent' : 'Paired key expiry e-mail not sent', ['scope' => $key['scope'], 'environment' => $environment, 'state' => $key['state']]);
            if ($done) {
                ++$sent;
            }
        }

        return $sent;
    }
}
