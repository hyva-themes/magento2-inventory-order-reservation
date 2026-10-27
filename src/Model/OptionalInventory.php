<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Magento\Framework\Module\Manager as ModuleManager;
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
    /** The Inventory modules whose services this class asks for; Composer autoloads their classes whether they are enabled or not. */
    private const REQUIRED_MODULES = [
        'Magento_InventorySales',
        'Magento_InventorySalesApi',
        'Magento_InventoryReservationsApi',
        'Magento_InventoryCatalogApi',
        'Magento_InventoryConfigurationApi',
    ];

    private ?bool $installed = null;

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ModuleManager $moduleManager
    ) {
    }

    /**
     * Whether this store has Magento Inventory. `interface_exists()`
     * autoloads, so it answers for the packages on disk.
     */
    /**
     * Whether Magento Inventory serves this store: its classes present and
     * its modules enabled. A store that disabled the Inventory modules keeps
     * their classes in Composer's autoloader, and asking the object manager
     * for an interface no enabled module prefers ends in an \Error inside
     * the order's save.
     */
    public function isInstalled(): bool
    {
        if ($this->installed === null) {
            $this->installed = interface_exists(self::REQUIRED_INTERFACE);
            foreach (self::REQUIRED_MODULES as $module) {
                $this->installed = $this->installed && $this->moduleManager->isEnabled($module);
            }
        }

        return $this->installed;
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
     */
    public function compensationStandardInput(): ?object
    {
        return $this->service('Magento\\InventoryReservationCli\\Command\\Input\\GetCommandlineStandardInput');
    }

    /**
     * The compensation command's parser of one argument into a reservation, present with Magento_InventoryReservationCli.
     */
    public function compensationArgumentReader(): ?object
    {
        return $this->service('Magento\\InventoryReservationCli\\Command\\Input\\GetReservationFromCompensationArgument');
    }


    /** @return \Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface|null */
    public function stockItemConfiguration(): ?object
    {
        return $this->service(\Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface::class);
    }

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
     */
    private function service(string $class): ?object
    {
        if (!$this->isInstalled() || !(interface_exists($class) || class_exists($class))) {
            return null;
        }
        try {
            return $this->objectManager->get($class);
        } catch (\Throwable) {
            // an interface without a preference, a class whose module is off
            return null;
        }
    }
}
