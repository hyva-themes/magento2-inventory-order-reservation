<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ObligationRecorder;
use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Sales\Api\Data\OrderExtensionInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\TestCase;

class ObligationRecorderTest extends TestCase
{
    public function test_a_flagged_order_is_recorded_per_sku_with_the_quantity_ordered(): void
    {
        $inserted = [];
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('insert')->willReturnCallback(function (array $rows) use (&$inserted): void {
            $inserted = $rows;
        });
        $inventory = $this->createMock(OptionalInventory::class);
        $inventory->method('isInstalled')->willReturn(true);
        $inventory->method('stockIdForWebsite')->with('base')->willReturn(2);
        $recorder = new ObligationRecorder($resource, $inventory, $this->websites('base'));
        $order = $this->order(77, [
            $this->item(1, 'plant-stand-teak', 'simple', 2.0),
            $this->item(2, 'plant-stand-teak', 'simple', 1.0),
            $this->item(3, 'mug', 'simple', 3.0),
        ], flagged: true);

        self::assertTrue($recorder->isRequested($order));
        $rows = $recorder->record($order);

        self::assertCount(2, $rows);
        self::assertSame($rows, $inserted);
        $bySku = [];
        foreach ($rows as $row) {
            $bySku[$row->sku] = $row;
        }
        self::assertSame(3.0, $bySku['plant-stand-teak']->qtyOrdered, 'two lines of one sku add up');
        self::assertSame(3.0, $bySku['mug']->qtyOrdered);
        self::assertSame(Obligation::MODE_INVENTORY, $bySku['mug']->mode);
        self::assertSame(2, $bySku['mug']->stockId);
        self::assertSame(77, $bySku['mug']->orderId);
        self::assertTrue($bySku['mug']->isOwed());
    }

    public function test_a_configurable_parent_row_is_skipped_for_its_child(): void
    {
        $resource = $this->createMock(ObligationResource::class);
        $inventory = $this->createMock(OptionalInventory::class);
        $inventory->method('isInstalled')->willReturn(false);
        $recorder = new ObligationRecorder($resource, $inventory, $this->websites('base'));
        $parent = $this->item(10, 'shirt-m-blue', 'configurable', 1.0);
        $child = $this->item(11, 'shirt-m-blue', 'simple', 1.0, parentItemId: 10);
        $order = $this->order(5, [$parent, $child], flagged: true);

        $rows = $recorder->record($order);

        self::assertCount(1, $rows);
        self::assertSame('simple', $rows[0]->productType);
        self::assertSame(1.0, $rows[0]->qtyOrdered, 'the child counts once');
        self::assertSame(Obligation::MODE_LEGACY, $rows[0]->mode);
        self::assertNull($rows[0]->stockId);
    }

    public function test_recorded_means_rows_exist_for_the_order(): void
    {
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('hasRowsFor')->willReturnMap([[77, true], [78, false]]);
        $recorder = new ObligationRecorder($resource, $this->createMock(OptionalInventory::class), $this->websites('base'));

        self::assertTrue($recorder->isRecorded($this->order(77, [], flagged: true)));
        self::assertFalse($recorder->isRecorded($this->order(78, [], flagged: true)));
    }

    public function test_an_order_without_the_flag_is_not_requested(): void
    {
        $recorder = new ObligationRecorder(
            $this->createMock(ObligationResource::class),
            $this->createMock(OptionalInventory::class),
            $this->websites('base')
        );
        self::assertFalse($recorder->isRequested($this->order(1, [], flagged: false)));
        self::assertFalse($recorder->isRequested($this->order(1, [], flagged: null)));
    }

    private function websites(string $code): WebsiteRepositoryInterface
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn($code);
        $repository = $this->createMock(WebsiteRepositoryInterface::class);
        $repository->method('getById')->willReturn($website);
        return $repository;
    }

    private function item(int $id, string $sku, string $type, float $qty, ?int $parentItemId = null): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()
            ->onlyMethods(['getItemId', 'getSku', 'getProductType', 'getQtyOrdered', 'getParentItemId', 'getParentItem'])
            ->getMock();
        $item->method('getItemId')->willReturn($id);
        $item->method('getSku')->willReturn($sku);
        $item->method('getProductType')->willReturn($type);
        $item->method('getQtyOrdered')->willReturn($qty);
        $item->method('getParentItemId')->willReturn($parentItemId);
        $item->method('getParentItem')->willReturn(null);
        return $item;
    }

    /**
     * @param Item[] $items
     */
    private function order(int $id, array $items, ?bool $flagged): OrderInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        // the generated extension interface carries the attribute where the
        // module is installed in the Magento the tests borrow; elsewhere it is added
        $extension = method_exists(OrderExtensionInterface::class, 'getReserveStock')
            ? $this->createMock(OrderExtensionInterface::class)
            : $this->getMockBuilder(OrderExtensionInterface::class)->addMethods(['getReserveStock', 'setReserveStock'])->getMock();
        $extension->method('getReserveStock')->willReturn($flagged);
        $order = $this->getMockBuilder(\Magento\Sales\Model\Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getEntityId', 'getItems', 'getStore', 'getExtensionAttributes'])->getMock();
        $order->method('getEntityId')->willReturn($id);
        $order->method('getItems')->willReturn($items);
        $order->method('getStore')->willReturn($store);
        $order->method('getExtensionAttributes')->willReturn($extension);
        return $order;
    }
}
