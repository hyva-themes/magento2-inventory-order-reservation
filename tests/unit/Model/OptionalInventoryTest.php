<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * Magento Inventory counts as present only with its modules enabled:
 * Composer keeps a disabled module's classes in the autoloader.
 */
class OptionalInventoryTest extends TestCase
{
    public function test_a_disabled_inventory_module_means_no_inventory(): void
    {
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn (string $name): bool => $name !== 'Magento_InventorySales');
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects(self::never())->method('get');
        $inventory = new OptionalInventory($objectManager, $modules);
        self::assertFalse($inventory->isInstalled());
        self::assertNull($inventory->stockResolver());
        self::assertNull($inventory->stockIdForWebsite('base'));
    }

    public function test_a_service_the_object_manager_cannot_make_is_null(): void
    {
        if (!interface_exists(\Magento\InventorySalesApi\Api\StockResolverInterface::class)) {
            self::markTestSkipped('the borrowed vendor has no Magento Inventory');
        }
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willThrowException(new \Error('Cannot instantiate interface'));
        $inventory = new OptionalInventory($objectManager, $modules);
        self::assertTrue($inventory->isInstalled());
        self::assertNull($inventory->stockResolver());
        self::assertNull($inventory->stockIdForWebsite('base'));
    }
}
