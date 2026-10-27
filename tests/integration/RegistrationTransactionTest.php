<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservation\Model\StockRegistration;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * One transaction covers the stock write and the completion marker: a
 * failure after the stock write leaves no stock movement and an owed
 * obligation with the failure on record; the next attempt completes it.
 */
class RegistrationTransactionTest extends StoreCase
{
    public function test_a_failure_after_the_inventory_write_rolls_the_write_back_and_the_next_attempt_completes(): void
    {
        $inventory = $this->inventoryInstalled();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        self::assertSame([], $this->obligations($orderId), 'no flag, no obligation');
        $this->insertOwed($orderId, 1.0);
        $stockBefore = $inventory ? $this->reservationSum($orderId, $this->websiteStockId()) : $this->legacyQty();

        $failing = new class(self::$objectManager->get(ResourceConnection::class)) extends ObligationResource {
            public function markRowDone(int $orderId, string $sku, string $settledBy): void
            {
                throw new \RuntimeException('simulated failure after the stock write');
            }
        };
        /** @var StockRegistration $registration */
        $registration = self::$objectManager->create(StockRegistration::class, ['obligations' => $failing]);
        try {
            $registration->register($orderId);
            self::fail('the simulated failure must surface');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('simulated failure', (string) $e->getPrevious()?->getMessage(), 'the cause travels with the exception');
        }

        $stockAfterFailure = $inventory ? $this->reservationSum($orderId, $this->websiteStockId()) : $this->legacyQty();
        self::assertSame($stockBefore, $stockAfterFailure, 'the stock write rolled back with the failure');
        if ($inventory) {
            self::assertSame([], $this->reservationRows($orderId), 'no reservation row survived the rollback');
        }
        $owed = $this->obligations($orderId)[0];
        self::assertTrue($owed->isOwed());
        self::assertSame(1, $owed->attempts, 'the attempt is on record, outside the rolled-back transaction');
        self::assertStringContainsString('simulated failure', (string) $owed->lastError);

        self::assertTrue($this->register($orderId), 'the next attempt writes');
        self::assertSame('done', $this->obligationStatus($orderId));
        if ($inventory) {
            self::assertSame(-1.0, $this->reservationSum($orderId, $this->websiteStockId()));
            self::assertSame(['order_placed:' . $this->websiteStockId() . ':-1.0'], $this->reservationEvents($orderId));
        } else {
            self::assertSame($stockBefore - 1.0, $this->legacyQty(), 'the legacy stock item is down by the unit');
        }
        self::assertFalse($this->register($orderId), 'a third call finds nothing owed and writes nothing');
    }

    public function test_a_failed_completion_of_one_sku_rolls_back_every_row_and_state_of_the_run(): void
    {
        $inventory = $this->inventoryInstalled();
        $stock = $inventory ? $this->websiteStockId() : 0;
        $orderId = (int) $this->placeOrderWithLines([self::SKU => 1.0, self::CHILD_SKU => 1.0], reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0, self::SKU);
        $this->insertOwed($orderId, 1.0, self::CHILD_SKU);
        $stockOf = fn (string $sku): float => $inventory ? $this->reservationSum($orderId, $stock, $sku) : $this->legacyQty($sku);
        $before = [$stockOf(self::SKU), $stockOf(self::CHILD_SKU)];

        // the first row's completion goes through, the second's fails inside the database
        $this->failSettlements(self::CHILD_SKU);
        try {
            $this->register($orderId);
            self::fail('the failed completion must surface');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('foreign key constraint fails', (string) $e->getPrevious()?->getMessage());
        } finally {
            $this->letSettlementsPass();
        }
        self::assertSame($before, [$stockOf(self::SKU), $stockOf(self::CHILD_SKU)], 'no stock movement of either sku survived');
        self::assertSame('owed', $this->obligationStatus($orderId, self::SKU), 'the completion of the first row rolled back with the failure of the second');
        self::assertSame('owed', $this->obligationStatus($orderId, self::CHILD_SKU));
        self::assertSame(1, $this->obligationAttempts($orderId, self::SKU), 'the attempt is on record for the run');
        self::assertSame(1, $this->obligationAttempts($orderId, self::CHILD_SKU));

        // the next run places both and completes both
        self::assertTrue($this->register($orderId));
        self::assertSame('done', $this->obligationStatus($orderId, self::SKU));
        self::assertSame('done', $this->obligationStatus($orderId, self::CHILD_SKU));
        self::assertSame([$before[0] - 1.0, $before[1] - 1.0], [$stockOf(self::SKU), $stockOf(self::CHILD_SKU)]);
        self::assertFalse($this->register($orderId), 'nothing left owed');
    }

    public function test_an_order_placed_with_the_flag_is_registered_once_in_the_placement_request(): void
    {
        $inventory = $this->inventoryInstalled();
        $legacyBefore = $this->legacyQty();
        $orderId = (int) $this->placeOrder(2.0, reserveStock: true)->getEntityId();

        self::assertSame('done', $this->obligationStatus($orderId));
        if ($inventory) {
            self::assertSame(['order_placed:' . $this->websiteStockId() . ':-2.0'], $this->reservationEvents($orderId));
        } else {
            self::assertSame($legacyBefore - 2.0, $this->legacyQty());
        }
        self::assertFalse($this->register($orderId), 'nothing left to register');
    }
}
