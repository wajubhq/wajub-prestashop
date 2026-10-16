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

use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Support\Ago;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the merchant must know in the back office, as the WordPress plugin's
 * Admin\Notices says it, read from what is saved, never from Wajub (a page
 * of the back office never waits on it).
 *
 * That the shop takes no payment at all (the environment chosen has no key
 * of its own) is said on every page. That a shop the public uses (debug
 * mode off) only takes test payments, that Wajub is not set up, that the
 * checkout cannot offer it, or that its scheduled task does not run, is
 * said where it matters: the dashboard, the module manager, the orders,
 * Payment's pages and the module's own page (RELEVANT, and narrower lists
 * for some). The keys "Connect with Wajub" created that expire are said by
 * Wajub::keyNotices(), beside these.
 *
 * A notice that can be hidden (HIDEABLE) comes back after a week, never
 * "forever": the state it warns about has not changed. Hidden for the
 * employee who hid it alone.
 */
final class Notices
{
    /** How long a notice an employee hid stays hidden, in seconds. */
    public const HIDE_FOR = 604800;

    /** Notices an employee may hide for a week. The others stay until fixed. */
    public const HIDEABLE = ['sandbox_live_shop', 'setup', 'not_offered', 'task'];

    /**
     * How long after its last run (or after the module began to wait for
     * it) the scheduled task is late: three times the hour PrestaShop's Cron
     * tasks manager runs it at, the host's cron running it every 15 minutes.
     */
    public const TASK_LATE_AFTER = 10800;

    /** The module's settings page, by its controller. */
    public const SETTINGS_PAGE = 'AdminWajubConfiguration';

    /**
     * The pages, besides the module's settings, where a notice about taking
     * payments belongs, by their controller (PrestaShop 8 names a Symfony
     * page by its legacy controller too): the dashboard, the module manager,
     * the orders and Payment's pages, as the plugin's Dashboard, Plugins
     * screen and WooCommerce's orders and settings.
     */
    public const RELEVANT = ['AdminDashboard', 'AdminModules', 'AdminModulesSf', 'AdminModulesManage', 'AdminOrders', 'AdminPayment', 'AdminPaymentPreferences'];

    /** Where "Finish setting up Wajub" is said: the plugin's Dashboard and Plugins screen. */
    private const SETUP_PAGES = ['AdminDashboard', 'AdminModules', 'AdminModulesSf', 'AdminModulesManage'];

    /** Where "Wajub is not offered at checkout" is said besides the module's page: Payment's pages, where it is fixed. */
    private const PAYMENT_PAGES = ['AdminPayment', 'AdminPaymentPreferences'];

    /** The notices an employee hid, with until when, in the module's state. */
    private const HIDDEN = 'notices_hidden:';

    /** @var Services */
    private $services;

    public function __construct(Services $services)
    {
        $this->services = $services;
    }

    /**
     * The notices due on the page whose controller is `$page` for the
     * employee, most serious first. Each says what fixes it: `action` names
     * a tab of the module's page ('connection', 'methods'), PrestaShop's
     * Payment > Preferences ('preferences'), or nothing; `copy` is an
     * address the merchant copies (the scheduled task's).
     *
     * @param callable(): bool $cronjobs whether PrestaShop's Cron tasks manager module is installed and on, asked only when the scheduled task is late
     *
     * @return list<array{id: string, type: string, title: string, text: string, action: string, label: string, hideable: bool, copy: string}>
     */
    public function due(string $page, int $employeeId, callable $cronjobs, int $now): array
    {
        $t = $this->services->translator();
        $environment = $this->services->environment();
        $settingsPage = $page === self::SETTINGS_PAGE;
        $relevant = $settingsPage || in_array($page, self::RELEVANT, true);
        $configured = $environment->isConfigured();
        $notices = [];

        // The environment chosen has no key, the other one has: no payment
        // at all, never one with the other environment's key.
        if ($environment->sandboxKeyMissing()) {
            $notices[] = self::notice(
                'no_payments',
                'error',
                $t->trans('Wajub takes no payments.', [], 'Modules.Wajub.Admin'),
                $t->trans('Sandbox is selected and has no key: add your sandbox key, or switch to Live to use your live key.', [], 'Modules.Wajub.Admin'),
                'connection',
                $t->trans('Add a sandbox key', [], 'Modules.Wajub.Admin')
            );
        } elseif ($environment->liveKeyMissing()) {
            $notices[] = self::notice(
                'no_payments',
                'error',
                $t->trans('Wajub takes no payments.', [], 'Modules.Wajub.Admin'),
                $t->trans('Live is selected and has no key: connect Live as well, or switch to Sandbox to use your sandbox key.', [], 'Modules.Wajub.Admin'),
                'connection',
                $t->trans('Add a live key', [], 'Modules.Wajub.Admin')
            );
        }
        $keyMissing = $notices !== [];

        // A shop in debug mode is a copy for development or testing: Sandbox
        // is what it should use.
        if ($configured && $environment->isSandbox() && $this->services->shop()->isProduction() && $relevant) {
            $notices[] = self::notice(
                'sandbox_live_shop',
                'warning',
                $t->trans('Wajub is in Sandbox.', [], 'Modules.Wajub.Admin'),
                $t->trans('This looks like your live shop, but payments are test payments: customers are not charged and orders are not paid for real. Switch to Live when you are ready.', [], 'Modules.Wajub.Admin'),
                'connection',
                $t->trans('Go live', [], 'Modules.Wajub.Admin')
            );
        }

        // The module's page is where it is set up: not said there.
        if (!$configured && !$keyMissing && in_array($page, self::SETUP_PAGES, true)) {
            $notices[] = self::notice(
                'setup',
                'info',
                $t->trans('Finish setting up Wajub.', [], 'Modules.Wajub.Admin'),
                $t->trans('Connect your Wajub account to start taking Mobile Money and card payments. It takes a few minutes.', [], 'Modules.Wajub.Admin'),
                'connection',
                $t->trans('Set up Wajub', [], 'Modules.Wajub.Admin')
            );
        }

        if ($configured && ($settingsPage || in_array($page, self::PAYMENT_PAGES, true))) {
            $why = $this->notOffered();
            if ($why !== null) {
                $notices[] = self::notice('not_offered', 'info', $t->trans('Wajub is not offered at checkout.', [], 'Modules.Wajub.Admin'), $why['text'], $why['action'], $why['label']);
            }
        }

        if ($configured && $relevant) {
            $task = $this->taskNotice($cronjobs, $now);
            if ($task !== null) {
                $notices[] = $task;
            }
        }

        $hidden = [];
        foreach ($notices as $notice) {
            if ($notice['hideable']) {
                $hidden = $this->hidden($employeeId, $now);
                break;
            }
        }

        return array_values(array_filter($notices, static function (array $notice) use ($hidden): bool {
            return !isset($hidden[$notice['id']]);
        }));
    }

    /**
     * Whether the back office's header shows "Wajub · Sandbox": the shop
     * takes test payments (as the plugin's toolbar says it, on every page).
     * Live, the normal state, shows nothing; neither does a shop that takes
     * no payment at all, which a notice says.
     */
    public function sandboxBadge(): bool
    {
        $environment = $this->services->environment();

        return $environment->isConfigured() && $environment->isSandbox();
    }

    /**
     * Hides a notice from the employee for a week (HIDE_FOR). False for a
     * notice that cannot be hidden.
     */
    public function hide(int $employeeId, string $id, int $now): bool
    {
        if ($employeeId <= 0 || !in_array($id, self::HIDEABLE, true)) {
            return false;
        }
        $hidden = $this->hidden($employeeId, $now);
        $hidden[$id] = $now + self::HIDE_FOR;
        $this->services->state()->set(self::HIDDEN . $employeeId, $hidden, self::HIDE_FOR);

        return true;
    }

    /**
     * The notices the employee hid that are still hidden, with until when.
     *
     * @return array<string, int>
     */
    private function hidden(int $employeeId, int $now): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        $stored = $this->services->state()->get(self::HIDDEN . $employeeId);
        $hidden = [];
        foreach (is_array($stored) ? $stored : [] as $id => $until) {
            if (is_string($id) && in_array($id, self::HIDEABLE, true) && is_numeric($until) && (int) $until > $now) {
                $hidden[$id] = (int) $until;
            }
        }

        return $hidden;
    }

    /**
     * Why no customer can choose Wajub at checkout, as its settings say
     * (every method off) or PrestaShop's (nothing ticked for the module in a
     * list of Payment > Preferences), or null. Whether the Wajub account
     * offers a method for a cart is known at checkout only.
     *
     * @return array{text: string, action: string, label: string}|null
     */
    private function notOffered(): ?array
    {
        $t = $this->services->translator();
        $settings = $this->services->settings();
        $on = false;
        foreach (Methods::KINDS as $kind) {
            $on = $on || $settings->methodEnabled($kind);
        }
        if (!$on) {
            return [
                'text' => $t->trans('Mobile Money, Card and Other payment methods are all turned off.', [], 'Modules.Wajub.Admin'),
                'action' => 'methods',
                'label' => $t->trans('Turn them on', [], 'Modules.Wajub.Admin'),
            ];
        }

        $names = [
            'currencies' => $t->trans('Currency restrictions', [], 'Modules.Wajub.Admin'),
            'countries' => $t->trans('Country restrictions', [], 'Modules.Wajub.Admin'),
            'groups' => $t->trans('Group restrictions', [], 'Modules.Wajub.Admin'),
            'carriers' => $t->trans('Carrier restrictions', [], 'Modules.Wajub.Admin'),
        ];
        $empty = [];
        foreach ($this->services->shop()->emptyPaymentPreferences() as $list) {
            if (isset($names[$list])) {
                $empty[] = $names[$list];
            }
        }
        if ($empty === []) {
            return null;
        }

        return [
            'text' => $t->trans('Nothing is ticked for Wajub under %sections% in Payment > Preferences, so customers cannot choose it.', ['%sections%' => implode(', ', $empty)], 'Modules.Wajub.Admin'),
            'action' => 'preferences',
            'label' => $t->trans('Open Payment preferences', [], 'Modules.Wajub.Admin'),
        ];
    }

    /**
     * The scheduled task that never ran, or stopped running: unpaid orders
     * stay, and payments that could not count are read again only from the
     * pages. Says how to run it, with its address to copy. A module
     * installed before the task was looked after (an update) is waited for
     * from the first page that asks.
     *
     * @param callable(): bool $cronjobs
     *
     * @return array{id: string, type: string, title: string, text: string, action: string, label: string, hideable: bool, copy: string}|null
     */
    private function taskNotice(callable $cronjobs, int $now): ?array
    {
        $log = $this->services->taskLog();
        $last = $log->lastRun();
        $since = $log->since();
        if ($since === 0) {
            $log->watch($now);
            $since = $now;
        }
        if ($now - ($last > 0 ? $last : $since) <= self::TASK_LATE_AFTER) {
            return null;
        }

        $t = $this->services->translator();
        $title = $last > 0
            ? $t->trans('Wajub’s scheduled task last ran %time% ago.', ['%time%' => Ago::since($last, $t, $now)], 'Modules.Wajub.Admin')
            : $t->trans('Wajub’s scheduled task has never run.', [], 'Modules.Wajub.Admin');
        $how = $cronjobs()
            ? $t->trans('PrestaShop’s Cron tasks manager module is installed but has not run it: check that its own task runs, or ask your web host to call this address every 15 minutes.', [], 'Modules.Wajub.Admin')
            : $t->trans('Install PrestaShop’s Cron tasks manager module, which runs it every hour, or ask your web host to call this address every 15 minutes.', [], 'Modules.Wajub.Admin');
        $notice = self::notice(
            'task',
            'warning',
            $title,
            $t->trans('It cancels the orders customers left unpaid, and reads again the payments Wajub could not tell about.', [], 'Modules.Wajub.Admin') . ' ' . $how,
            '',
            ''
        );
        $notice['copy'] = $this->services->shop()->cronUrl($this->services->tokens()->cron());

        return $notice;
    }

    /**
     * @return array{id: string, type: string, title: string, text: string, action: string, label: string, hideable: bool, copy: string}
     */
    private static function notice(string $id, string $type, string $title, string $text, string $action, string $label): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'text' => $text,
            'action' => $action,
            'label' => $label,
            'hideable' => in_array($id, self::HIDEABLE, true),
            'copy' => '',
        ];
    }
}
