<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

/**
 * One sku of what an order owed at its creation. Read and written through
 * ObligationResource; never modelled as an ORM entity, because the rows are
 * written inside the order's own save and read inside the order's lock.
 */
class Obligation
{
    public const STATUS_OWED = 'owed';
    public const STATUS_DONE = 'done';

    public const MODE_INVENTORY = 'inventory';
    public const MODE_LEGACY = 'legacy';

    public const SETTLED_BY_REGISTRATION = 'registration';
    public const SETTLED_BY_COMPENSATION = 'compensation';

    /**
     * Constructor.
     * @param int $orderId
     * @param string $sku
     * @param ?string $productType
     * @param float $qtyOrdered
     * @param int $websiteId
     * @param ?int $stockId
     * @param string $mode
     * @param string $status
     * @param ?string $settledBy
     * @param int $attempts
     * @param ?string $lastError
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $sku,
        public readonly ?string $productType,
        public readonly float $qtyOrdered,
        public readonly int $websiteId,
        public readonly ?int $stockId,
        public readonly string $mode,
        public readonly string $status = self::STATUS_OWED,
        public readonly ?string $settledBy = null,
        public readonly int $attempts = 0,
        public readonly ?string $lastError = null
    ) {
    }

    /**
     * Whether the stock side of this sku is still to be registered.
     *
     * @return bool
     */
    public function isOwed(): bool
    {
        return $this->status === self::STATUS_OWED;
    }
}
