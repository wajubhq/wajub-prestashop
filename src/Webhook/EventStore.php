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
 * The events the shop received, so that each is acted on once: Wajub sends
 * an event again until the shop answers 2xx, and two deliveries of one
 * event may arrive together.
 */
interface EventStore
{
    /** This request acts on the event. */
    public const CLAIMED = 'claimed';

    /** Acted on already: answered as received, and nothing is done again. */
    public const DUPLICATE = 'duplicate';

    /** Another request is acting on it right now: Wajub is asked to send it again later. */
    public const BUSY = 'busy';

    public const PROCESSED = 'processed';

    /** Verified, and of a type no handler acts on: no shop of this PrestaShop does. */
    public const IGNORED = 'ignored';

    /** Acting on it failed: the next delivery tries again. */
    public const FAILED = 'failed';

    /**
     * Takes the event for this request, in one statement: of two requests
     * that claim it at once, one only gets CLAIMED.
     */
    public function claim(string $eventId, int $idShop, string $environment, string $type, string $objectId): string;

    /** How acting on a claimed event ended: PROCESSED, IGNORED or FAILED. */
    public function finish(string $eventId, string $status, string $error = ''): void;

    /**
     * Forgets a claimed event this shop does not act on (another
     * environment's, or a live one in a copy of the shop): as if it never
     * came, so the next delivery of it, here or to another shop of this
     * PrestaShop, is claimed and acted on.
     */
    public function release(string $eventId): void;

    /** Forgets the events older than this many days. */
    public function purge(int $days): void;
}
