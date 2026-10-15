<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the module reads of a PrestaShop order, read afresh each time
 * (ShopOrders::find()).
 *
 * A cart that ships in several packages becomes several orders under one
 * reference, which one payment pays: the module treats them as one, its
 * total theirs (PrestaShop links a payment to the reference too). `id` is
 * the first of them, the one a payment names.
 */
final class OrderView
{
    /**
     * @param list<int> $ids every order of the reference made from the same cart, `id` first
     */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly int $idShop,
        public readonly int $idCart,
        public readonly int $idCustomer,
        public readonly int $idLang,
        public readonly string $currency,
        public readonly float $total,
        public readonly int $stateId,
        public readonly bool $paid,
        public readonly bool $paidOnce,
        public readonly string $module,
        public readonly string $paymentTitle,
        public readonly string $secureKey,
        public readonly array $ids = [],
    ) {
    }

    /** Whether the order was placed with this module: the only orders it settles. */
    public function isWajub(): bool
    {
        return $this->module === 'wajub';
    }
}
