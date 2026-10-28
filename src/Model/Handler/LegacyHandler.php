<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Handler;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockStateException;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexerProcessor;
use Magento\CatalogInventory\Model\ResourceModel\Stock as StockResource;
use Magento\CatalogInventory\Model\StockManagement;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * A store without Magento Inventory: the legacy sale registration, what
 * the quote submit does on checkout (`StockManagement::registerProductsSale`),
 * so the stock item reads what the shelf holds, with the stock index
 * refreshed for the rows whose status changed. The price index follows
 * from the stock item's own save, which queues it after the commit when
 * the item's stock status changes.
 */
class LegacyHandler implements HandlerInterface
{
    public function __construct(
        private readonly StockManagementInterface $stockManagement,
        private readonly ProductResource $productResource,
        private readonly StockIndexerProcessor $stockIndexerProcessor,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockResource $stockResource,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly StockRegistryStorage $stockRegistryStorage
    ) {
    }

    /**
     * Registers the sale on the legacy stock items for the owed skus and refreshes the stock index; every row with a quantity is placed, a row without is balanced.
     * @throws LocalizedException when a sku is not in the catalog
     */
    public function register(OrderInterface $order, array $owed): array
    {
        $this->deduct($order, $owed);
        return array_map(
            static fn (Obligation $row): RowOutcome => $row->qtyOrdered > 0
                ? new RowOutcome($row, RowOutcome::PLACED, $row->qtyOrdered)
                : new RowOutcome($row, RowOutcome::BALANCED),
            $owed
        );
    }

    /**
     * The deduction itself, for the rows with a quantity.
     *
     * @param Obligation[] $owed
     * @throws LocalizedException when a sku is not in the catalog
     */
    private function deduct(OrderInterface $order, array $owed): void
    {
        $qtyByProduct = [];
        $skuByProduct = [];
        $withoutProduct = [];
        foreach ($owed as $obligation) {
            if ($obligation->qtyOrdered <= 0) {
                continue;
            }
            if ($obligation->productId !== null) {
                $qtyByProduct[$obligation->productId] = ($qtyByProduct[$obligation->productId] ?? 0.0) + $obligation->qtyOrdered;
                $skuByProduct[$obligation->productId] = $obligation->sku;
            } else {
                $withoutProduct[$obligation->sku] = ($withoutProduct[$obligation->sku] ?? 0.0) + $obligation->qtyOrdered;
            }
        }
        // rows recorded before the product id was kept: the catalog's spelling of the sku decides
        if ($withoutProduct !== []) {
            $idsBySku = $this->productResource->getProductsIdsBySkus(array_keys($withoutProduct));
            $lower = array_change_key_case($idsBySku, CASE_LOWER);
            foreach ($withoutProduct as $sku => $qty) {
                $productId = $idsBySku[$sku] ?? $lower[strtolower((string) $sku)] ?? null;
                if ($productId === null) {
                    throw new LocalizedException(__('Product "%1" is not in the catalog; its stock cannot be registered.', $sku));
                }
                $qtyByProduct[(int) $productId] = ($qtyByProduct[(int) $productId] ?? 0.0) + $qty;
                $skuByProduct[(int) $productId] = (string) $sku;
            }
        }
        if ($qtyByProduct === []) {
            return;
        }
        $websiteId = (int) $order->getStore()->getWebsiteId();
        $fullSave = [];
        $soldOut = false;
        try {
            // registerProductsSale answers the stock items whose status or
            // notification changed and need a full save; the checkout saves
            // them the same way (SubtractQuoteInventoryObserver)
            $fullSave = $this->stockManagement->registerProductsSale($qtyByProduct, $websiteId);
            foreach ($fullSave as $stockItem) {
                $stockItem->save();
            }
        } catch (StockStateException) {
            // The goods left the shop: a line the stock item cannot cover
            // (backorders off) is deducted the way an admin order's is,
            // past zero, instead of staying owed for good.
            $soldOut = true;
            $this->deductPastZero($qtyByProduct, $skuByProduct);
        }
        if ($this->stockManagement instanceof StockManagement || $fullSave !== [] || $soldOut) {
            $this->stockIndexerProcessor->reindexList(array_keys($qtyByProduct));
        }
    }

    /**
     * What `registerProductsSale` does without its quantity check, which
     * the admin area's stock item skips: the quantity taken off each
     * item, saved through the stock item repository, which decides the
     * in-stock flag as it does for a checkout's sale: an item it takes out
     * of stock is marked as changed automatically, so a later replenishment
     * puts it back in stock, and an item set out of stock by hand stays so.
     *
     * The quantity comes from the stock rows, read under the lock
     * `registerProductsSale` took on them in this transaction, as that
     * method reads it, and the stock item is loaded afresh, as that method
     * loads it: one the registry cached earlier in the process (a cron run
     * registers many orders) may be out of date, and its save writes every
     * field it holds.
     *
     * @param array<int, float> $qtyByProduct
     * @param array<int, string> $skuByProduct
     */
    private function deductPastZero(array $qtyByProduct, array $skuByProduct): void
    {
        $scopeId = (int) $this->stockConfiguration->getDefaultScopeId();
        $locked = $this->stockResource->lockProductsStock(array_keys($qtyByProduct), $scopeId);
        foreach ($qtyByProduct as $productId => $qty) {
            $row = $locked[$productId] ?? null;
            if (!isset($row['qty']) || !$this->stockConfiguration->isQty((string) ($row['type_id'] ?? ''))) {
                continue;
            }
            $this->stockRegistryStorage->removeStockItem($productId);
            $stockItem = $this->stockRegistry->getStockItem($productId, $scopeId);
            if (!$stockItem->getItemId() || !$stockItem->getManageStock()) {
                continue;
            }
            $stockItem->setQty((float) $row['qty'] - $qty);
            $this->stockRegistry->updateStockItemBySku($skuByProduct[$productId], $stockItem);
        }
    }
}
