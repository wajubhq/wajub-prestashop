<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Storage;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Short-lived state the module keeps between requests, with an expiry: a
 * pairing session, a lock, a rate limit, a cached answer of Wajub, the last
 * webhook delivery. PrestaShop has no such store of its own, and its
 * Configuration is the wrong place for values that change on every request.
 *
 * Values are anything json_encode() keeps: never an object.
 */
interface StateStore
{
    /** The value, or null when there is none or it expired. */
    public function get(string $name): mixed;

    /**
     * @param int $ttl seconds it lives; 0 keeps it until it is deleted
     */
    public function set(string $name, mixed $value, int $ttl = 0): void;

    /**
     * Saves the value only when the name holds none (or an expired one), in
     * one statement: of two requests that add the same name at once, one
     * only gets true. What a lock is made of.
     */
    public function add(string $name, mixed $value, int $ttl = 0): bool;

    public function delete(string $name): void;

    /**
     * Adds one to a counter and returns it: 1 for a counter that did not
     * exist or had expired, which then lives `ttl` seconds.
     */
    public function increment(string $name, int $ttl): int;
}
