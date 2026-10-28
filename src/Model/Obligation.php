<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

/**
 * One sku of what an order owed at its creation. Read and written through
 * ObligationResource; never modeled as an ORM entity, because the rows are
 * written inside the order's own save and read inside the order's lock.
 */
class Obligation
{
    public const STATUS_OWED = 'owed';
    public const STATUS_DONE = 'done';
    /** The registration gave up after MAX_ATTEMPTS; Magento's own listing and a compensation take over. */
    public const STATUS_REFUSED = 'refused';
    /** Attempts before the registration gives up: two hours of the five-minute cron. */
    public const MAX_ATTEMPTS = 24;
    /** Quantities within this of each other are the same quantity. */
    public const TOLERANCE = 0.00005;

    public const MODE_INVENTORY = 'inventory';
    public const MODE_LEGACY = 'legacy';

    public const SETTLED_BY_REGISTRATION = 'registration';
    public const SETTLED_BY_COMPENSATION = 'compensation';
    public const SETTLED_BY_BALANCED = 'balanced';
    /** Closed with inventory:order-reservation:settle; no stock was written. */
    public const SETTLED_BY_HAND = 'manual';

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
        public readonly ?string $lastError = null,
        public readonly ?int $productId = null
    ) {
    }

    /**
     * Whether the stock side of this sku is still to be registered.
     *
     */
    public function isOwed(): bool
    {
        return $this->status === self::STATUS_OWED;
    }

    public function isRefused(): bool
    {
        return $this->status === self::STATUS_REFUSED;
    }
}
