<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\InventoryPresence;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Magento\Framework\Module\Manager;
use PHPUnit\Framework\TestCase;

class InventoryPresenceTest extends TestCase
{
    public function test_magento_inventory_sales_enabled_is_an_inventory_store(): void
    {
        $presence = $this->presence(enabled: ['Magento_InventorySales', 'Magento_InventorySalesApi', 'Magento_InventoryReservations', 'Magento_InventoryReservationsApi', 'Magento_InventoryCatalog', 'Magento_InventoryCatalogApi', 'Magento_InventoryConfiguration', 'Magento_InventoryConfigurationApi']);
        self::assertTrue($presence->isInventory());
        self::assertSame(Obligation::MODE_INVENTORY, $presence->mode());
        self::assertSame([], $presence->missingModules());
    }

    public function test_an_implementation_module_disabled_is_an_installation_problem_and_not_a_legacy_store(): void
    {
        // the reservation builder and writer are Magento_InventoryReservations'; the API module alone does not place anything
        $presence = $this->presence(enabled: ['Magento_InventorySales', 'Magento_InventorySalesApi', 'Magento_InventoryReservationsApi', 'Magento_InventoryCatalog', 'Magento_InventoryCatalogApi', 'Magento_InventoryConfiguration', 'Magento_InventoryConfigurationApi']);
        self::assertSame(Obligation::MODE_INVENTORY, $presence->mode(), 'still an Inventory store');
        self::assertSame(['Magento_InventoryReservations'], $presence->missingModules());
    }

    public function test_magento_inventory_sales_disabled_is_a_legacy_store_with_nothing_missing(): void
    {
        $presence = $this->presence(enabled: ['Magento_InventoryReservations']);
        self::assertFalse($presence->isInventory());
        self::assertSame(Obligation::MODE_LEGACY, $presence->mode());
        self::assertSame([], $presence->missingModules(), 'a legacy store misses nothing');
    }

    /** @param string[] $enabled */
    private function presence(array $enabled): InventoryPresence
    {
        $manager = $this->createStub(Manager::class);
        $manager->method('isEnabled')->willReturnCallback(static fn (string $module): bool => in_array($module, $enabled, true));
        return new InventoryPresence($manager);
    }
}
