<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Handler;

use Hyva\InventoryOrderReservation\Model\Handler\LegacyHandler;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexerProcessor;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\CatalogInventory\Model\StockManagement;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexerProcessor;
use Magento\CatalogInventory\Model\Spi\StockStateProviderInterface;
use Magento\CatalogInventory\Model\StockStateException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * The legacy stock item: the sale registered by product id as the
 * checkout does, and a line the item cannot cover deducted past zero,
 * as an admin order's is.
 */
class LegacyHandlerTest extends TestCase
{
    public function test_the_sale_is_registered_by_the_product_id_the_row_carries(): void
    {
        $management = $this->management();
        $management->expects(self::once())->method('registerProductsSale')->with([100 => 2.0, 200 => 1.0], 1)->willReturn([]);
        $products = $this->createMock(ProductResource::class);
        $products->expects(self::never())->method('getProductsIdsBySkus');
        $handler = $this->handler($management, $products, $this->createMock(StockRegistryInterface::class));
        $handler->register($this->order(), [
            new Obligation(9, 'mug', 'simple', 2.0, 1, null, Obligation::MODE_LEGACY, productId: 100),
            new Obligation(9, 'Spoon', 'simple', 1.0, 1, null, Obligation::MODE_LEGACY, productId: 200),
        ]);
    }

    public function test_a_sold_out_line_is_deducted_past_zero(): void
    {
        $management = $this->management();
        $management->method('registerProductsSale')->willThrowException(new StockStateException(__('Some of the products are out of stock.')));
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn(5);
        $stockItem->method('getManageStock')->willReturn(true);
        $stockItem->method('getQty')->willReturn(0.0);
        $stockItem->expects(self::once())->method('setQty')->with(-2.0);
        $stockItem->expects(self::once())->method('setIsInStock')->with(false);
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItem')->with(100, 1)->willReturn($stockItem);
        $registry->expects(self::once())->method('updateStockItemBySku')->with('mug', $stockItem);
        $handler = $this->handler($management, $this->createMock(ProductResource::class), $registry);
        $handler->register($this->order(), [new Obligation(9, 'mug', 'simple', 2.0, 1, null, Obligation::MODE_LEGACY, productId: 100)]);
    }

    public function test_the_price_index_is_refreshed_for_the_items_whose_status_changed(): void
    {
        // the checkout's full save is the model's save(), not on the interface
        $changed = $this->createStub(\Magento\CatalogInventory\Model\Stock\Item::class);
        $changed->method('getProductId')->willReturn(200);
        $management = $this->management();
        $management->method('registerProductsSale')->willReturn([$changed]);
        $prices = $this->priceIndexer();
        $prices->expects(self::once())->method('reindexList')->with([200]);
        $handler = $this->handler($management, $this->createStub(ProductResource::class), $this->createStub(StockRegistryInterface::class), $prices);
        $handler->register($this->order(), [
            new Obligation(9, 'mug', 'simple', 2.0, 1, null, Obligation::MODE_LEGACY, productId: 100),
            new Obligation(9, 'Spoon', 'simple', 1.0, 1, null, Obligation::MODE_LEGACY, productId: 200),
        ]);
    }

    public function test_no_price_reindex_when_no_status_changed(): void
    {
        $management = $this->management();
        $management->method('registerProductsSale')->willReturn([]);
        $prices = $this->priceIndexer();
        $prices->expects(self::never())->method('reindexList');
        $handler = $this->handler($management, $this->createStub(ProductResource::class), $this->createStub(StockRegistryInterface::class), $prices);
        $handler->register($this->order(), [new Obligation(9, 'mug', 'simple', 2.0, 1, null, Obligation::MODE_LEGACY, productId: 100)]);
    }

    /**
     * The interface names `registerProductsSale` in a docblock only; the
     * class declares it, so the mock is of the class.
     */
    private function management(): StockManagement
    {
        return $this->getMockBuilder(StockManagement::class)->disableOriginalConstructor()->onlyMethods(['registerProductsSale'])->getMock();
    }

    private function handler(
        StockManagementInterface $management,
        ProductResource $products,
        StockRegistryInterface $registry,
        ?PriceIndexerProcessor $prices = null
    ): LegacyHandler {
        $state = $this->createMock(StockStateProviderInterface::class);
        $state->method('verifyStock')->willReturn(false);
        $indexer = $this->getMockBuilder(StockIndexerProcessor::class)->disableOriginalConstructor()->onlyMethods(['reindexList'])->getMock();

        return new LegacyHandler($management, $products, $indexer, $registry, $state, $prices ?? $this->priceIndexer());
    }

    private function priceIndexer(): PriceIndexerProcessor
    {
        return $this->getMockBuilder(PriceIndexerProcessor::class)->disableOriginalConstructor()->onlyMethods(['reindexList'])->getMock();
    }

    private function order(): OrderInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $order = $this->getMockBuilder(\Magento\Sales\Model\Order::class)->disableOriginalConstructor()->onlyMethods(['getStore'])->getMock();
        $order->method('getStore')->willReturn($store);

        return $order;
    }
}
