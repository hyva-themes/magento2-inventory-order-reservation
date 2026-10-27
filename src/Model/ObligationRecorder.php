<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * Turns a new flagged order into its obligation rows: one per sku, the
 * quantity ordered at creation, the product type, the website and its
 * stock, and the store's mode. Written inside the order's own save.
 */
class ObligationRecorder
{
    public function __construct(
        private readonly ObligationResource $obligations,
        private readonly OptionalInventory $inventory,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly ProductIdentities $products
    ) {
    }

    /**
     * Whether the order asks for the stock side of a placed order.
     */
    public function isRequested(OrderInterface $order): bool
    {
        $extension = $order->getExtensionAttributes();
        if ($extension !== null && method_exists($extension, 'getReserveStock')) {
            return (bool) $extension->getReserveStock();
        }
        return false;
    }

    /**
     * Whether the order's obligation was recorded already: the rows exist.
     *
     */
    public function isRecorded(OrderInterface $order): bool
    {
        return $this->obligations->hasRowsFor((int) $order->getEntityId());
    }

    /** @return Obligation[] what was recorded
     */
    public function record(OrderInterface $order): array
    {
        $websiteId = (int) $order->getStore()->getWebsiteId();
        $mode = $this->inventory->isInstalled() ? Obligation::MODE_INVENTORY : Obligation::MODE_LEGACY;
        $stockId = null;
        if ($mode === Obligation::MODE_INVENTORY) {
            $stockId = $this->inventory->stockIdForWebsite(
                (string) $this->websiteRepository->getById($websiteId)->getCode()
            );
        }
        // As Magento's own placement does (AppendReservationsAfterOrderPlacementPlugin):
        // the quantity ordered per product id, every line counted, then the
        // sku and type the catalog gives that product. A composite product
        // (configurable, bundle, grouped) has no stock of its own and its
        // children carry theirs, so its line is left out, whether or not the
        // payload named parent and child. A product the catalog no longer
        // holds keeps the line's own sku and type.
        $qtyByProduct = [];
        $lineSku = [];
        $lineType = [];
        foreach ($order->getItems() as $item) {
            $productId = (int) $item->getProductId();
            $qty = (float) $item->getQtyOrdered();
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            $qtyByProduct[$productId] = ($qtyByProduct[$productId] ?? 0.0) + $qty;
            $lineSku[$productId] ??= (string) $item->getSku();
            $lineType[$productId] ??= (string) $item->getProductType();
        }
        $identities = $this->products->byProductIds(array_keys($qtyByProduct));
        $rows = [];
        foreach ($qtyByProduct as $productId => $qty) {
            $sku = $identities[$productId]['sku'] ?? $lineSku[$productId];
            $type = $identities[$productId]['type'] ?? $lineType[$productId];
            if ($sku === '' || ProductIdentities::isComposite($type)) {
                continue;
            }
            $rows[] = new Obligation(
                (int) $order->getEntityId(),
                $sku,
                $type !== '' ? $type : null,
                $qty,
                $websiteId,
                $stockId,
                $mode,
                productId: $productId
            );
        }
        $this->obligations->insert($rows);
        return $rows;
    }
}
