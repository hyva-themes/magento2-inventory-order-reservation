<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Compensation;

use Hyva\InventoryOrderReservation\Model\Compensation\RemainingInconsistency;
use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\TestCase;

class RemainingInconsistencyTest extends TestCase
{
    public function test_a_final_order_expects_zero(): void
    {
        $order = $this->order(Order::STATE_COMPLETE, [$this->item('mug', 'simple', 3, 0, 0, 3, 3, false)]);
        self::assertSame(0.0, $this->service()->expectedSum($order, 'mug'));
    }

    public function test_an_open_order_expects_minus_what_is_neither_cancelled_refunded_nor_shipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, [
            $this->item('mug', 'simple', 5, 1, 1, 1, 0, false),
            $this->item('mug', 'simple', 2, 0, 0, 0, 0, false),
            $this->item('other', 'simple', 9, 0, 0, 0, 0, false),
        ]);
        // 5 - 1 - 1 - 1 = 2, plus 2 untouched: minus 4
        self::assertSame(-4.0, $this->service()->expectedSum($order, 'mug'));
    }

    public function test_a_virtual_line_counts_what_is_invoiced_instead_of_shipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, [$this->item('ebook', 'virtual', 2, 0, 0, 0, 2, true)]);
        self::assertSame(0.0, $this->service()->expectedSum($order, 'ebook'));
    }

    public function test_a_type_inventory_does_not_manage_is_left_out(): void
    {
        $managed = new class {
            public function execute(string $type): bool
            {
                return $type !== 'configurable';
            }
        };
        $order = $this->order(Order::STATE_PROCESSING, [
            $this->item('shirt', 'configurable', 1, 0, 0, 0, 0, false),
            $this->item('shirt', 'simple', 1, 0, 0, 0, 0, false),
        ]);
        self::assertSame(-1.0, $this->service($managed)->expectedSum($order, 'shirt'));
    }

    private function service(?object $managed = null): RemainingInconsistency
    {
        $inventory = $this->createMock(OptionalInventory::class);
        $inventory->method('isSourceItemManagementAllowedForProductType')->willReturn($managed);
        return new RemainingInconsistency($this->createMock(ResourceConnection::class), $inventory);
    }

    private function item(string $sku, string $type, float $ordered, float $canceled, float $refunded, float $shipped, float $invoiced, bool $virtual): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()
            ->onlyMethods(['getSku', 'getProductType', 'getQtyOrdered', 'getQtyCanceled', 'getQtyRefunded', 'getQtyShipped', 'getQtyInvoiced', 'getIsVirtual'])
            ->getMock();
        $item->method('getSku')->willReturn($sku);
        $item->method('getProductType')->willReturn($type);
        $item->method('getQtyOrdered')->willReturn($ordered);
        $item->method('getQtyCanceled')->willReturn($canceled);
        $item->method('getQtyRefunded')->willReturn($refunded);
        $item->method('getQtyShipped')->willReturn($shipped);
        $item->method('getQtyInvoiced')->willReturn($invoiced);
        $item->method('getIsVirtual')->willReturn($virtual);
        return $item;
    }

    /** @param Item[] $items */
    private function order(string $state, array $items): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getState', 'getItems'])->getMock();
        $order->method('getState')->willReturn($state);
        $order->method('getItems')->willReturn($items);
        return $order;
    }
}
