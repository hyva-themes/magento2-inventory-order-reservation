<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

/**
 * A compensation settles the sku it balances and nothing else: an order
 * the registration gave up on with two skus stays unresolved until each
 * sku has been repaired.
 */
class PartialRepairTest extends StoreCase
{
    public function test_a_compensation_settles_one_sku_of_a_refused_order_at_a_time(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrderWithLines([self::SKU => 1.0, self::CHILD_SKU => 1.0], reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0, self::SKU);
        $this->insertOwed($orderId, 1.0, self::CHILD_SKU);
        $this->refuse($orderId);
        self::assertContains($orderId, $this->orderIdsRefused(), 'given up: listed as unresolved');

        // the first sku repaired: its row settles, the other keeps its discrepancy
        $this->append([$this->manualCompensation($orderId, $stock, -1.0, self::SKU)]);
        self::assertSame('done', $this->obligationStatus($orderId, self::SKU));
        self::assertSame('refused', $this->obligationStatus($orderId, self::CHILD_SKU), 'the other sku keeps its discrepancy');
        self::assertContains($orderId, $this->orderIdsRefused(), 'the order stays listed');
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock, self::SKU));
        self::assertSame(0.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU));

        // the first sku again: balanced, nothing written, no state change
        $rows = count($this->reservationRows($orderId, self::SKU));
        $this->appendExpectingBalanced($orderId, $stock, -1.0, self::SKU);
        self::assertCount($rows, $this->reservationRows($orderId, self::SKU), 'no reservation row added');
        self::assertSame('done', $this->obligationStatus($orderId, self::SKU));
        self::assertSame('refused', $this->obligationStatus($orderId, self::CHILD_SKU));

        // the second sku repaired: the order leaves the unresolved list
        $this->append([$this->manualCompensation($orderId, $stock, -1.0, self::CHILD_SKU)]);
        self::assertSame('done', $this->obligationStatus($orderId, self::CHILD_SKU));
        self::assertNotContains($orderId, $this->orderIdsRefused(), 'every sku settled: no longer listed');
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU));
    }
}
