<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Handler;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexerProcessor;
use Magento\CatalogInventory\Model\StockManagement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * A store without Magento Inventory: the legacy sale registration, what
 * the quote submit does on checkout (`StockManagement::registerProductsSale`),
 * so the stock item reads what the shelf holds, with the stock index
 * refreshed for the rows whose status changed.
 */
class LegacyHandler implements HandlerInterface
{
    /**
     * Constructor.
     * @param StockManagementInterface $stockManagement
     * @param ProductResource $productResource
     * @param StockIndexerProcessor $stockIndexerProcessor
     */
    public function __construct(
        private readonly StockManagementInterface $stockManagement,
        private readonly ProductResource $productResource,
        private readonly StockIndexerProcessor $stockIndexerProcessor
    ) {
    }

    /**
     * Registers the sale on the legacy stock items for the owed skus and refreshes the stock index.
     * @param OrderInterface $order
     * @param Obligation[] $owed
     * @return void
     * @throws LocalizedException when a sku is not in the catalog
     */
    public function register(OrderInterface $order, array $owed): void
    {
        $qtyBySku = [];
        foreach ($owed as $obligation) {
            if ($obligation->qtyOrdered > 0) {
                $qtyBySku[$obligation->sku] = ($qtyBySku[$obligation->sku] ?? 0.0) + $obligation->qtyOrdered;
            }
        }
        if ($qtyBySku === []) {
            return;
        }
        $idsBySku = $this->productResource->getProductsIdsBySkus(array_keys($qtyBySku));
        $items = [];
        foreach ($qtyBySku as $sku => $qty) {
            $productId = $idsBySku[$sku] ?? $idsBySku[strtolower((string) $sku)] ?? null;
            if ($productId === null) {
                throw new LocalizedException(__('Product "%1" is not in the catalog; its stock cannot be registered.', $sku));
            }
            $items[(int) $productId] = $qty;
        }
        $websiteId = (int) $order->getStore()->getWebsiteId();
        // registerProductsSale answers the stock items whose status or
        // notification changed and need a full save; the checkout saves
        // them the same way (SubtractQuoteInventoryObserver)
        $fullSave = $this->stockManagement->registerProductsSale($items, $websiteId);
        foreach ($fullSave as $stockItem) {
            $stockItem->save();
        }
        if ($this->stockManagement instanceof StockManagement || $fullSave !== []) {
            $this->stockIndexerProcessor->reindexList(array_keys($items));
        }
    }
}
