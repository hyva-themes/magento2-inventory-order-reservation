<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
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
    /**
     * Constructor.
     * @param ObligationResource $obligations
     * @param OptionalInventory $inventory
     * @param WebsiteRepositoryInterface $websiteRepository
     */
    public function __construct(
        private readonly ObligationResource $obligations,
        private readonly OptionalInventory $inventory,
        private readonly WebsiteRepositoryInterface $websiteRepository
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
     * @param OrderInterface $order
     * @return bool
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
        $qtyBySku = [];
        $typeBySku = [];
        foreach ($order->getItems() as $item) {
            $sku = (string) $item->getSku();
            if ($sku === '' || (float) $item->getQtyOrdered() <= 0) {
                continue;
            }
            // a configurable or bundle parent carries its children's skus
            // under its own type; the child rows carry the quantity that
            // leaves the shelf. The parent row is skipped so the child's
            // sku is counted once, at the child's own type.
            if ($item->getParentItemId() === null && $this->hasChildren($order, $item)) {
                continue;
            }
            $qtyBySku[$sku] = ($qtyBySku[$sku] ?? 0.0) + (float) $item->getQtyOrdered();
            if (!isset($typeBySku[$sku])) {
                $typeBySku[$sku] = (string) $item->getProductType();
            }
        }
        $rows = [];
        foreach ($qtyBySku as $sku => $qty) {
            $rows[] = new Obligation(
                (int) $order->getEntityId(),
                (string) $sku,
                $typeBySku[$sku] !== '' ? $typeBySku[$sku] : null,
                $qty,
                $websiteId,
                $stockId,
                $mode
            );
        }
        $this->obligations->insert($rows);
        return $rows;
    }

    /**
     * Whether the line is a parent whose children carry the quantities (a configurable, a bundle).
     * @param OrderInterface $order
     * @param object $parent
     * @return bool
     */
    private function hasChildren(OrderInterface $order, object $parent): bool
    {
        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId() !== null && (int) $item->getParentItemId() === (int) $parent->getItemId()) {
                return true;
            }
            if (method_exists($item, 'getParentItem') && $item->getParentItem() === $parent) {
                return true;
            }
        }
        return false;
    }
}
