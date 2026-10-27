<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ObligationRecorder;
use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Hyva\InventoryOrderReservation\Model\ProductIdentities;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * The obligation an order carries from its creation: one row per product
 * with the quantity ordered, under the catalog's sku and type, as
 * Magento's own placement reads a line.
 */
class ObligationRecorderTest extends TestCase
{
    /** @var array<int, array{sku: string, type: string}> what the catalog says per product id */
    private array $catalog = [];

    public function test_a_flagged_order_is_recorded_per_product_with_the_quantity_ordered(): void
    {
        $inserted = [];
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('insert')->willReturnCallback(function (array $rows) use (&$inserted): void {
            $inserted = $rows;
        });
        $inventory = $this->createMock(OptionalInventory::class);
        $inventory->method('isInstalled')->willReturn(true);
        $inventory->method('stockIdForWebsite')->with('base')->willReturn(2);
        $this->catalog = [100 => ['sku' => 'plant-stand-teak', 'type' => 'simple'], 200 => ['sku' => 'mug', 'type' => 'simple']];
        $recorder = $this->recorder($resource, $inventory);
        $order = $this->order(77, [
            $this->item(1, 100, 'plant-stand-teak', 'simple', 2.0),
            $this->item(2, 100, 'plant-stand-teak', 'simple', 1.0),
            $this->item(3, 200, 'mug', 'simple', 3.0),
        ], flagged: true);
        self::assertTrue($recorder->isRequested($order));
        $rows = $recorder->record($order);
        self::assertCount(2, $rows);
        self::assertSame($rows, $inserted);
        $bySku = [];
        foreach ($rows as $row) {
            $bySku[$row->sku] = $row;
        }
        self::assertSame(3.0, $bySku['plant-stand-teak']->qtyOrdered, 'two lines of one product add up');
        self::assertSame(100, $bySku['plant-stand-teak']->productId);
        self::assertSame(3.0, $bySku['mug']->qtyOrdered);
        self::assertSame(Obligation::MODE_INVENTORY, $bySku['mug']->mode);
        self::assertSame(2, $bySku['mug']->stockId);
        self::assertSame(77, $bySku['mug']->orderId);
        self::assertTrue($bySku['mug']->isOwed());
    }

    public function test_a_line_is_recorded_under_the_catalogs_sku_not_the_lines(): void
    {
        $this->catalog = [300 => ['sku' => 'SHIRT', 'type' => 'simple']];
        $recorder = $this->recorder($this->createMock(ObligationResource::class), $this->legacy());
        $rows = $recorder->record($this->order(5, [$this->item(1, 300, 'SHIRT-engr', 'simple', 1.0)], flagged: true));
        self::assertCount(1, $rows);
        self::assertSame('SHIRT', $rows[0]->sku, 'the custom-option sku of the line is not what the stock is keyed by');
        self::assertSame(300, $rows[0]->productId);
    }

    public function test_a_composite_parent_is_left_to_its_child_whether_or_not_the_payload_links_them(): void
    {
        $this->catalog = [10 => ['sku' => 'shirt', 'type' => 'configurable'], 11 => ['sku' => 'shirt-m-blue', 'type' => 'simple']];
        $recorder = $this->recorder($this->createMock(ObligationResource::class), $this->legacy());
        // the parent names the child's sku under its own type, and the
        // child has no parent id yet when the order's items are not saved
        $parent = $this->item(1, 10, 'shirt-m-blue', 'configurable', 1.0);
        $child = $this->item(2, 11, 'shirt-m-blue', 'simple', 1.0);
        foreach ([[$parent, $child], [$child, $parent]] as $items) {
            $rows = $recorder->record($this->order(5, $items, flagged: true));
            self::assertCount(1, $rows);
            self::assertSame('simple', $rows[0]->productType);
            self::assertSame(11, $rows[0]->productId);
            self::assertSame(1.0, $rows[0]->qtyOrdered, 'the child counts once');
            self::assertSame(Obligation::MODE_LEGACY, $rows[0]->mode);
            self::assertNull($rows[0]->stockId);
        }
    }

    public function test_a_grouped_products_lines_are_its_children(): void
    {
        $this->catalog = [40 => ['sku' => 'spoon', 'type' => 'simple']];
        $recorder = $this->recorder($this->createMock(ObligationResource::class), $this->legacy());
        // the line carries the grouped parent's type; the catalog's type for the product decides
        $rows = $recorder->record($this->order(6, [$this->item(1, 40, 'spoon', 'grouped', 2.0)], flagged: true));
        self::assertCount(1, $rows);
        self::assertSame('simple', $rows[0]->productType);
    }

    public function test_a_product_gone_from_the_catalog_keeps_the_lines_sku_and_type(): void
    {
        $this->catalog = [];
        $recorder = $this->recorder($this->createMock(ObligationResource::class), $this->legacy());
        $rows = $recorder->record($this->order(7, [$this->item(1, 999, 'gone', 'simple', 1.0)], flagged: true));
        self::assertCount(1, $rows);
        self::assertSame('gone', $rows[0]->sku);
        self::assertSame(999, $rows[0]->productId);
    }

    public function test_recorded_means_rows_exist_for_the_order(): void
    {
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('hasRowsFor')->willReturnMap([[77, true], [78, false]]);
        $recorder = $this->recorder($resource, $this->createMock(OptionalInventory::class));
        self::assertTrue($recorder->isRecorded($this->order(77, [], flagged: true)));
        self::assertFalse($recorder->isRecorded($this->order(78, [], flagged: true)));
    }

    public function test_an_order_without_the_flag_is_not_requested(): void
    {
        $recorder = $this->recorder($this->createMock(ObligationResource::class), $this->createMock(OptionalInventory::class));
        self::assertFalse($recorder->isRequested($this->order(1, [], flagged: false)));
        self::assertFalse($recorder->isRequested($this->order(1, [], flagged: null)));
    }

    private function recorder(ObligationResource $resource, OptionalInventory $inventory): ObligationRecorder
    {
        $products = $this->createMock(ProductIdentities::class);
        $products->method('byProductIds')->willReturnCallback(
            fn (array $ids): array => array_intersect_key($this->catalog, array_flip(array_map('intval', $ids)))
        );

        return new ObligationRecorder($resource, $inventory, $this->websites('base'), $products);
    }

    private function legacy(): OptionalInventory
    {
        $inventory = $this->createMock(OptionalInventory::class);
        $inventory->method('isInstalled')->willReturn(false);

        return $inventory;
    }

    private function websites(string $code): WebsiteRepositoryInterface
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn($code);
        $repository = $this->createMock(WebsiteRepositoryInterface::class);
        $repository->method('getById')->willReturn($website);

        return $repository;
    }

    private function item(int $id, int $productId, string $sku, string $type, float $qty): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()
            ->onlyMethods(['getItemId', 'getProductId', 'getSku', 'getProductType', 'getQtyOrdered'])
            ->getMock();
        $item->method('getItemId')->willReturn($id);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getSku')->willReturn($sku);
        $item->method('getProductType')->willReturn($type);
        $item->method('getQtyOrdered')->willReturn($qty);

        return $item;
    }

    /**
     * @param Item[] $items
     */
    private function order(int $id, array $items, ?bool $flagged): OrderInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        // the extension attribute as the generated interface carries it where
        // the module is installed; a plain object answers the same on any vendor
        $extension = new class($flagged) {
            public function __construct(private readonly ?bool $flagged)
            {
            }

            public function getReserveStock(): ?bool
            {
                return $this->flagged;
            }
        };
        $order = $this->getMockBuilder(\Magento\Sales\Model\Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getEntityId', 'getItems', 'getStore', 'getExtensionAttributes'])->getMock();
        $order->method('getEntityId')->willReturn($id);
        $order->method('getItems')->willReturn($items);
        $order->method('getStore')->willReturn($store);
        $order->method('getExtensionAttributes')->willReturn($extension);

        return $order;
    }
}
