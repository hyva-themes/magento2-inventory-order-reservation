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
use Hyva\InventoryOrderReservation\Model\Readiness;
use Hyva\InventoryOrderReservation\Model\ResourceModel\WebsiteStock;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\TestCase;

class ReadinessTest extends TestCase
{
    public function test_an_inventory_store_with_the_handler_package_and_a_stock_is_ready(): void
    {
        $readiness = $this->readiness(inventory: true, missing: [], handlers: ['inventory'], stockId: 2);
        self::assertNull($readiness->problem(1));
        self::assertTrue($readiness->isReady(1));
    }

    public function test_a_legacy_store_with_its_handler_is_ready_and_asks_for_no_stock(): void
    {
        $readiness = $this->readiness(inventory: false, missing: [], handlers: ['legacy'], stockId: null);
        self::assertNull($readiness->problem(1));
    }

    public function test_an_inventory_store_without_the_handler_package_names_it(): void
    {
        $readiness = $this->readiness(inventory: true, missing: [], handlers: ['legacy'], stockId: 2);
        self::assertSame(
            'Magento Inventory is enabled, but Hyva_InventoryOrderReservationMsi, which places the reservation, is not installed.',
            $readiness->problem()
        );
    }

    public function test_an_incomplete_inventory_installation_names_the_missing_modules_and_stays_an_inventory_store(): void
    {
        $readiness = $this->readiness(inventory: true, missing: ['Magento_InventoryReservations'], handlers: ['inventory'], stockId: 2);
        self::assertSame(
            'Magento Inventory is enabled, but these of its modules are disabled or missing: Magento_InventoryReservations.',
            $readiness->problem()
        );
    }

    public function test_a_website_assigned_to_no_stock_is_a_problem_for_that_website_only(): void
    {
        $readiness = $this->readiness(inventory: true, missing: [], handlers: ['inventory'], stockId: null);
        self::assertNull($readiness->problem(), 'the store as installed is fine');
        self::assertSame('Website "base" is assigned to no stock.', $readiness->problem(1));
    }

    /**
     * @param string[] $missing
     * @param string[] $handlers the modes with a handler installed
     */
    private function readiness(bool $inventory, array $missing, array $handlers, ?int $stockId): Readiness
    {
        $presence = $this->createStub(InventoryPresence::class);
        $presence->method('mode')->willReturn($inventory ? Obligation::MODE_INVENTORY : Obligation::MODE_LEGACY);
        $presence->method('missingModules')->willReturn($missing);
        $pool = new class (array_fill_keys($handlers, true)) implements \ArrayAccess {
            public function __construct(private array $items)
            {
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->items[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->items[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->items[$offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->items[$offset]);
            }
        };
        $websiteStock = $this->createMock(WebsiteStock::class);
        $websiteStock->method('idForWebsiteCode')->with('base')->willReturn($stockId);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $websites = $this->createStub(WebsiteRepositoryInterface::class);
        $websites->method('getById')->willReturn($website);
        return new Readiness($presence, $pool, $websiteStock, $websites);
    }
}
