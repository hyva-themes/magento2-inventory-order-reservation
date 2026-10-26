<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Handler;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * The order_placed reservation, through Inventory's sales-event service
 * (the one Adobe's documentation names for a business event), with a
 * sales event of type order_placed for the order and the website's sales
 * channel. One item to sell per sku, for the product types Inventory
 * manages, at minus the quantity ordered at creation.
 */
class InventoryHandler implements HandlerInterface
{
    public const EVENT_ORDER_PLACED = 'order_placed';

    /**
     * Constructor.
     * @param OptionalInventory $inventory
     * @param WebsiteRepositoryInterface $websiteRepository
     */
    public function __construct(
        private readonly OptionalInventory $inventory,
        private readonly WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    /**
     * Places the order_placed reservation for the owed skus through Inventory's sales-event service.
     * @param OrderInterface $order
     * @param Obligation[] $owed
     * @return void
     * @throws LocalizedException when Inventory is not available
     */
    public function register(OrderInterface $order, array $owed): void
    {
        $place = $this->inventory->placeReservationsForSalesEvent();
        $itemFactory = $this->inventory->itemToSellFactory();
        $channelFactory = $this->inventory->salesChannelFactory();
        $eventFactory = $this->inventory->salesEventFactory();
        $extensionFactory = $this->inventory->salesEventExtensionFactory();
        $managed = $this->inventory->isSourceItemManagementAllowedForProductType();
        if ($place === null || $itemFactory === null || $channelFactory === null
            || $eventFactory === null || $extensionFactory === null || $managed === null
        ) {
            throw new LocalizedException(__('Magento Inventory is not available on this store.'));
        }
        $items = [];
        foreach ($owed as $obligation) {
            if ($obligation->productType !== null && $obligation->productType !== ''
                && !$managed->execute($obligation->productType)
            ) {
                continue;
            }
            if ($obligation->qtyOrdered <= 0) {
                continue;
            }
            $items[] = $itemFactory->create(['sku' => $obligation->sku, 'qty' => -$obligation->qtyOrdered]);
        }
        if ($items === []) {
            return;
        }
        $websiteId = (int) $order->getStore()->getWebsiteId();
        $websiteCode = (string) $this->websiteRepository->getById($websiteId)->getCode();
        $salesChannel = $channelFactory->create(['data' => ['type' => 'website', 'code' => $websiteCode]]);
        $salesEvent = $eventFactory->create([
            'type' => self::EVENT_ORDER_PLACED,
            'objectType' => 'order',
            'objectId' => (string) $order->getEntityId(),
        ]);
        $salesEvent->setExtensionAttributes($extensionFactory->create([
            'data' => ['objectIncrementId' => (string) $order->getIncrementId()],
        ]));
        $place->execute($items, $salesChannel, $salesEvent);
    }
}
