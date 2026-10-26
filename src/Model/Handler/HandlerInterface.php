<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Handler;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Writes the stock side of a placed order for what the order still owes.
 * Called inside the order's lock and transaction; must throw on failure so
 * the transaction rolls the write back with the completion marker.
 */
interface HandlerInterface
{
    /** @param OrderInterface $order
     * @param Obligation[] $owed the owed rows, one per sku
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function register(OrderInterface $order, array $owed): void;
}
