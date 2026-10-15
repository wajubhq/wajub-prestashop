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
 * One delivery Wajub signed: its event id, its type (`payment.succeeded`),
 * the object it is about (`data`), and the environment of the signing
 * secret that verified it, the one thing about it nobody else can write.
 */
final class Event
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data,
        public readonly string $environment,
    ) {
    }

    /** The Wajub id of the object (`trx_…`, `rfd_…`, `dsp_…`), or ''. */
    public function objectId(): string
    {
        $id = $this->data['id'] ?? '';

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1 ? $id : '';
    }
}
