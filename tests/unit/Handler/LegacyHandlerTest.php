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
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\CatalogInventory\Model\StockManagement;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexerProcessor;
use Magento\CatalogInventory\Model\ResourceModel\Stock as StockResource;
use Magento\CatalogInventory\Model\Spi\StockStateProviderInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
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
        $handler = $this->handler($management, $products, $this->createStub(StockRegistryInterface::class));
        $handler->register($this->order(), [
            new Obligation(9, 'mug', 'simple', 2.0, 1, null, Obligation::MODE_LEGACY, productId: 100),
            new Obligation(9, 'Spoon', 'simple', 1.0, 1, null, Obligation::MODE_LEGACY, productId: 200),
        ]);
    }

    public function test_a_sold_out_line_is_deducted_past_zero_from_the_locked_quantity(): void
    {
        $management = $this->createStub(StockManagement::class);
        $management->method('registerProductsSale')->willThrowException(new StockStateException(__('Some of the products are out of stock.')));
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn(5);
        $stockItem->method('getManageStock')->willReturn(true);
        // the registry's copy is stale; the locked row holds the real quantity
        $stockItem->method('getQty')->willReturn(7.0);
        $stockItem->expects(self::once())->method('setQty')->with(-2.0);
        $stockItem->expects(self::once())->method('setIsInStock')->with(false);
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItem')->with(100, 0)->willReturn($stockItem);
        $registry->expects(self::once())->method('updateStockItemBySku')->with('mug', $stockItem);
        $storage = $this->createMock(StockRegistryStorage::class);
        $storage->expects(self::once())->method('removeStockItem')->with(100);
        $handler = $this->handler($management, $this->createStub(ProductResource::class), $registry, locked: [100 => ['product_id' => 100, 'qty' => '0.0000', 'type_id' => 'simple']], storage: $storage);
        $handler->register($this->order(), [new Obligation(9, 'mug', 'simple', 2.0, 1, null, Obligation::MODE_LEGACY, productId: 100)]);
    }

    public function test_a_type_without_quantity_is_not_deducted_past_zero(): void
    {
        $management = $this->createStub(StockManagement::class);
        $management->method('registerProductsSale')->willThrowException(new StockStateException(__('Some of the products are out of stock.')));
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->expects(self::never())->method('updateStockItemBySku');
        $handler = $this->handler($management, $this->createStub(ProductResource::class), $registry, locked: [100 => ['product_id' => 100, 'qty' => '0.0000', 'type_id' => 'not-qty']]);
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
        array $locked = [],
        ?StockRegistryStorage $storage = null
    ): LegacyHandler {
        $state = $this->createStub(StockStateProviderInterface::class);
        $state->method('verifyStock')->willReturn(false);
        $indexer = $this->createStub(StockIndexerProcessor::class);

        $stockResource = $this->createStub(StockResource::class);
        $stockResource->method('lockProductsStock')->willReturn($locked);
        $configuration = $this->createStub(StockConfigurationInterface::class);
        $configuration->method('getDefaultScopeId')->willReturn(0);
        $configuration->method('isQty')->willReturnCallback(static fn (string $type): bool => $type === 'simple');

        return new LegacyHandler($management, $products, $indexer, $registry, $state, $stockResource, $configuration, $storage ?? $this->createStub(StockRegistryStorage::class));
    }

    private function order(): OrderInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $order = $this->createStub(\Magento\Sales\Model\Order::class);
        $order->method('getStore')->willReturn($store);

        return $order;
    }
}
