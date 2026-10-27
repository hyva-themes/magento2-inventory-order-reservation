<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Whether Magento Inventory serves this store, read from the module list
 * and nothing else: Magento_InventorySales enabled is what disconnects the
 * legacy stock deduction (its plugin on registerProductsSale returns
 * without deducting), so a store with it is an Inventory store whatever
 * else is installed, and a store without it is a legacy store. Presence
 * decides the mode; readiness (the other Inventory modules, the handler
 * package) is reported separately.
 */
class InventoryPresence
{
    /** The module whose presence decides the mode. */
    public const MODE_MODULE = 'Magento_InventorySales';

    /**
     * The Inventory modules the handler package reads, the implementations
     * and their API modules; a missing one is an installation problem, not
     * a mode. The reservation builder and writer are Magento_InventoryReservations',
     * the product-type lookup Magento_InventoryCatalog's, the source-item rule
     * and the stock-item configuration Magento_InventoryConfiguration's.
     */
    private const REQUIRED_MODULES = [
        'Magento_InventorySales',
        'Magento_InventorySalesApi',
        'Magento_InventoryReservations',
        'Magento_InventoryReservationsApi',
        'Magento_InventoryCatalog',
        'Magento_InventoryCatalogApi',
        'Magento_InventoryConfiguration',
        'Magento_InventoryConfigurationApi',
    ];

    public function __construct(
        private readonly ModuleManager $moduleManager
    ) {
    }

    public function isInventory(): bool
    {
        return $this->moduleManager->isEnabled(self::MODE_MODULE);
    }

    /**
     * The store's mode: Obligation::MODE_INVENTORY or Obligation::MODE_LEGACY.
     */
    public function mode(): string
    {
        return $this->isInventory() ? Obligation::MODE_INVENTORY : Obligation::MODE_LEGACY;
    }

    /**
     * The Inventory modules the handler package needs that are not enabled; empty on a complete installation and on a legacy store.
     *
     * @return string[]
     */
    public function missingModules(): array
    {
        if (!$this->isInventory()) {
            return [];
        }
        return array_values(array_filter(
            self::REQUIRED_MODULES,
            fn (string $module): bool => !$this->moduleManager->isEnabled($module)
        ));
    }
}
