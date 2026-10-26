<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Magento\Framework\ObjectManagerInterface;

/**
 * Magento Inventory, for the stores that have it.
 *
 * Inventory is a set of separate modules that many stores run without
 * (`yireo/magento2-replace-inventory` takes them out altogether). This
 * module installs and serves on such a store, so no class of it may name an
 * Inventory type in a constructor: `setup:di:compile` reflects every
 * constructor and fails on a type that is not there. The Inventory services
 * are resolved here, behind `interface_exists()`, and nothing else in the
 * module mentions an Inventory type in a constructor signature.
 */
class OptionalInventory
{
    private const PLACE_RESERVATIONS = \Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface::class;
    private const ITEM_TO_SELL_FACTORY = \Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory::class;
    private const SALES_CHANNEL_FACTORY = \Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory::class;
    private const SALES_EVENT_FACTORY = \Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory::class;
    private const SALES_EVENT_EXTENSION_FACTORY = \Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory::class;
    private const STOCK_RESOLVER = \Magento\InventorySalesApi\Api\StockResolverInterface::class;
    private const PRODUCT_TYPES_BY_SKUS = \Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface::class;
    private const MANAGEMENT_ALLOWED = \Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface::class;
    private const APPEND_RESERVATIONS = \Magento\InventoryReservationsApi\Model\AppendReservationsInterface::class;
    private const REQUIRED_INTERFACE = \Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface::class;

    /**
     * Constructor.
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Whether this store has Magento Inventory. `interface_exists()`
     * autoloads, so it answers for the packages on disk.
     */
    public function isInstalled(): bool
    {
        return interface_exists(self::REQUIRED_INTERFACE);
    }

    /** @return \Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface|null */
    public function placeReservationsForSalesEvent(): ?object
    {
        return $this->service(self::PLACE_RESERVATIONS);
    }

    /** @return \Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory|null */
    public function itemToSellFactory(): ?object
    {
        return $this->service(self::ITEM_TO_SELL_FACTORY);
    }

    /** @return \Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory|null */
    public function salesChannelFactory(): ?object
    {
        return $this->service(self::SALES_CHANNEL_FACTORY);
    }

    /** @return \Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory|null */
    public function salesEventFactory(): ?object
    {
        return $this->service(self::SALES_EVENT_FACTORY);
    }

    /** @return \Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory|null */
    public function salesEventExtensionFactory(): ?object
    {
        return $this->service(self::SALES_EVENT_EXTENSION_FACTORY);
    }

    /**
     * Inventory's low-level reservation writer, for the coordinated
     * compensation alone (a business event goes through
     * placeReservationsForSalesEvent, as Adobe's documentation says).
     * @return \Magento\InventoryReservationsApi\Model\AppendReservationsInterface|null
     */
    public function appendReservations(): ?object
    {
        return $this->service(self::APPEND_RESERVATIONS);
    }

    /** @return \Magento\InventorySalesApi\Api\StockResolverInterface|null */
    public function stockResolver(): ?object
    {
        return $this->service(self::STOCK_RESOLVER);
    }
    /**
     * The compensation command's reader of its standard input, present with Magento_InventoryReservationCli.
     * @return \Magento\InventoryReservationCli\Command\Input\GetCommandlineStandardInput|null
     */
    public function compensationStandardInput(): ?object
    {
        return $this->service('Magento\\InventoryReservationCli\\Command\\Input\\GetCommandlineStandardInput');
    }

    /**
     * The compensation command's parser of one argument into a reservation, present with Magento_InventoryReservationCli.
     * @return \Magento\InventoryReservationCli\Command\Input\GetReservationFromCompensationArgument|null
     */
    public function compensationArgumentReader(): ?object
    {
        return $this->service('Magento\\InventoryReservationCli\\Command\\Input\\GetReservationFromCompensationArgument');
    }


    /** @return \Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface|null */
    public function productTypesBySkus(): ?object
    {
        return $this->service(self::PRODUCT_TYPES_BY_SKUS);
    }

    /** @return \Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface|null */
    public function isSourceItemManagementAllowedForProductType(): ?object
    {
        return $this->service(self::MANAGEMENT_ALLOWED);
    }

    /**
     * The stock a website sells from, or null without Inventory or when
     * the resolver does not answer.
     */
    public function stockIdForWebsite(string $websiteCode): ?int
    {
        $resolver = $this->stockResolver();
        if ($resolver === null) {
            return null;
        }
        try {
            return (int) $resolver->execute('website', $websiteCode)->getStockId();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The Inventory service, or null where the store has no Inventory or that service is missing.
     * @param string $class
     * @return object|null
     */
    private function service(string $class): ?object
    {
        if (!$this->isInstalled() || !(interface_exists($class) || class_exists($class))) {
            return null;
        }
        return $this->objectManager->get($class);
    }
}
