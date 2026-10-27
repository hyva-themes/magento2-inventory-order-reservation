<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Api;

/**
 * Registers the stock side of a placed order for an order created through
 * the order repository: the Inventory reservation, or the legacy stock
 * deduction. Runs under the order's lock; exactly once per obligation.
 */
interface StockRegistrationInterface
{
    /**
     * Register what the order still owes. Answers true when this call
     * wrote the stock, false when nothing was owed (already done, or no
     * obligation on record).
     * @param int $orderId
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException when the store cannot register it now; the obligation stays owed
     */
    public function register(int $orderId): bool;
}
