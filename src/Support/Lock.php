<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A lock held while one request acts on an order or a cart: the webhook and
 * the payer's return read the same payment at the same moment, and a
 * double click places the same cart twice. Whoever holds it acts; the other
 * waits for it, a few seconds at most.
 */
interface Lock
{
    /** Takes the lock, waiting up to `$wait` seconds for whoever holds it. True when taken. */
    public function acquire(string $name, int $wait): bool;

    public function release(string $name): void;
}
