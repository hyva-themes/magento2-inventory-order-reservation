<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

use Magento\Sales\Model\Order;

/**
 * An order whose registration is still owed when it is shipped,
 * cancelled or refunded with return to stock: the order's next save
 * registers it, and the stock ends where Magento's own rule puts it
 * (a final-state order sums to zero; the goods that left are off the
 * source or the stock item).
 */
class DelayedRegistrationTest extends StoreCase
{
    public function test_shipment_before_registration_ends_balanced_with_the_source_down(): void
    {
        $inventory = $this->inventoryInstalled();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);
        $sources = $inventory ? $this->websiteSources() : [];
        $sourceBefore = $inventory ? $this->sourceQty($sources[0]) : $this->legacyQty();

        // invoiced, then shipped, as the register does it: complete needs both
        $this->invoice($orderId);
        $this->ship($orderId, $inventory ? $sources[0] : null);

        self::assertSame(Order::STATE_COMPLETE, $this->orderState($orderId));
        self::assertSame('done', $this->obligationStatus($orderId), 'the shipment\'s order save registered the owed stock');
        if ($inventory) {
            self::assertSame($sourceBefore - 1.0, $this->sourceQty($sources[0]), 'the source is down by the unit shipped');
            self::assertSame(0.0, $this->reservationSum($orderId, $this->websiteStockId()), 'placement and shipment cancel out');
            self::assertEqualsCanonicalizing(
                ['shipment_created:' . $this->websiteStockId() . ':+1.0', 'order_placed:' . $this->websiteStockId() . ':-1.0'],
                $this->reservationEvents($orderId)
            );
        } else {
            self::assertSame($sourceBefore - 1.0, $this->legacyQty(), 'the stock item is down by the unit, once');
        }
        self::assertFalse($this->register($orderId));
    }

    public function test_cancellation_before_registration_ends_balanced(): void
    {
        $inventory = $this->inventoryInstalled();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);
        $legacyBefore = $this->legacyQty();

        $this->cancel($orderId);

        self::assertSame(Order::STATE_CANCELED, $this->orderState($orderId));
        self::assertSame('done', $this->obligationStatus($orderId));
        if ($inventory) {
            self::assertSame(0.0, $this->reservationSum($orderId, $this->websiteStockId()), 'cancellation and placement cancel out');
        } else {
            // Magento's legacy cancel returns the unit to the stock item only
            // with "Automatically Return Credit Memo Item to Stock" on
            // (CancelOrderItemObserver, getCanBackInStock); otherwise a
            // checkout-placed order stays deducted after its cancellation.
            // The delayed registration ends in the same place.
            $autoReturn = (bool) self::$objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)
                ->getValue('cataloginventory/item_options/auto_return');
            self::assertSame(
                $autoReturn ? $legacyBefore : $legacyBefore - 1.0,
                $this->legacyQty(),
                'the stock item ends where a checkout-placed order ends after its cancellation'
            );
        }
    }

    public function test_refund_with_return_to_stock_before_registration_ends_balanced(): void
    {
        $inventory = $this->inventoryInstalled();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);
        $legacyBefore = $this->legacyQty();

        $this->invoice($orderId);
        $this->refundReturningToStock($orderId);

        self::assertSame(Order::STATE_CLOSED, $this->orderState($orderId));
        self::assertSame('done', $this->obligationStatus($orderId));
        if ($inventory) {
            self::assertSame(
                0.0,
                $this->reservationSum($orderId, $this->websiteStockId()),
                'the credit memo\'s return and the placement cancel out: ' . implode(' ', $this->reservationEvents($orderId))
            );
        } else {
            self::assertSame($legacyBefore, $this->legacyQty(), 'the stock item ends where it started');
        }
    }
}
