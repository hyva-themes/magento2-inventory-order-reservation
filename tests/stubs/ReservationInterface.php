<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 *
 * The shape of Magento Inventory's reservation, for the compensation
 * tests on a Magento without Inventory. Loaded by the bootstrap only
 * when the real interface is absent.
 */

declare(strict_types=1);

namespace Magento\InventoryReservationsApi\Model;

interface ReservationInterface
{
    /**
     * @return int
     */
    public function getStockId(): int;

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @return float
     */
    public function getQuantity(): float;

    /**
     * @return string|null
     */
    public function getMetadata(): ?string;
}
