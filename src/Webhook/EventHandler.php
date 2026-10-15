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
 * What acts on a kind of event: the payments' handler settles orders, the
 * refunds' handler records refunds. An exception thrown here leaves the
 * event to be delivered again: Wajub sends it again while the shop answers
 * an error.
 */
interface EventHandler
{
    public function handles(string $type): bool;

    public function handle(Event $event): void;
}
