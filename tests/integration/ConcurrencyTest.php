<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

/**
 * Two registrations of one order: the second waits on the order's lock and
 * finds the first's work committed, so nothing is written twice.
 */
class ConcurrencyTest extends StoreCase
{
    public function test_two_registrations_write_once(): void
    {
        $inventory = $this->inventoryInstalled();
        $legacyBefore = $this->legacyQty();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);

        $child = $this->holdLockWhileRegistering($orderId, 3);
        $second = $this->register($orderId);
        $this->finish($child);

        self::assertFalse($second, 'the second runner found the first\'s work committed');
        if ($inventory) {
            self::assertSame(['order_placed:' . $this->websiteStockId() . ':-1.0'], $this->reservationEvents($orderId));
        } else {
            self::assertSame($legacyBefore - 1.0, $this->legacyQty());
        }
    }
}
