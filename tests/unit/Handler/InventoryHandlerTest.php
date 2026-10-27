<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Handler;

use Hyva\InventoryOrderReservation\Model\Handler\InventoryHandler;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\TestCase;

class InventoryHandlerTest extends TestCase
{
    public function test_one_item_to_sell_per_sku_at_minus_the_ordered_quantity_under_an_order_placed_event(): void
    {
        $placed = [];
        $inventory = $this->inventory($placed, managed: static fn (string $type): bool => $type !== 'bundle');
        $handler = new InventoryHandler($inventory, $this->websites('base'));
        $owed = [
            new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'kit', 'bundle', 1.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'free', 'simple', 0.0, 1, 2, Obligation::MODE_INVENTORY),
        ];

        $handler->register($this->order(9, '000000009'), $owed);

        self::assertCount(1, $placed);
        [$items, $channel, $event] = $placed[0];
        // the recorder leaves composite products out; Magento's placement
        // reads each sku's type from the catalog, so nothing is filtered here
        self::assertSame([['sku' => 'mug', 'qty' => -2.0], ['sku' => 'kit', 'qty' => -1.0]], $items);
        self::assertSame(['type' => 'website', 'code' => 'base'], $channel);
        self::assertSame(['type' => 'order_placed', 'objectType' => 'order', 'objectId' => '9'], $event);
    }

    public function test_without_inventory_the_handler_refuses(): void
    {
        $inventory = $this->createMock(OptionalInventory::class);
        $handler = new InventoryHandler($inventory, $this->websites('base'));
        $this->expectException(LocalizedException::class);
        $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY)]);
    }

    /**
     * @param array<int, array{0: array, 1: array, 2: array}> $placed
     */
    private function inventory(array &$placed, callable $managed): OptionalInventory
    {
        $inventory = $this->createMock(OptionalInventory::class);
        $factory = static fn (): object => new class {
            public function create(array $data): object
            {
                return new class($data) {
                    public ?object $extension = null;
                    public function __construct(public array $data)
                    {
                    }
                    public function setExtensionAttributes(object $extension): void
                    {
                        $this->extension = $extension;
                    }
                };
            }
        };
        $inventory->method('placeReservationsForSalesEvent')->willReturn(new class($placed) {
            public function __construct(private array &$placed)
            {
            }
            public function execute(array $items, object $channel, object $event): void
            {
                $this->placed[] = [
                    array_map(static fn (object $i): array => $i->data, $items),
                    $channel->data['data'],
                    $event->data,
                ];
            }
        });
        $inventory->method('itemToSellFactory')->willReturn($factory());
        $inventory->method('salesChannelFactory')->willReturn($factory());
        $inventory->method('salesEventFactory')->willReturn($factory());
        $inventory->method('salesEventExtensionFactory')->willReturn($factory());
        $inventory->method('isSourceItemManagementAllowedForProductType')->willReturn(new class($managed) {
            public function __construct(private $managed)
            {
            }
            public function execute(string $type): bool
            {
                return ($this->managed)($type);
            }
        });
        return $inventory;
    }

    private function websites(string $code): WebsiteRepositoryInterface
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn($code);
        $repository = $this->createMock(WebsiteRepositoryInterface::class);
        $repository->method('getById')->willReturn($website);
        return $repository;
    }

    private function order(int $id, string $increment): Order
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getEntityId', 'getIncrementId', 'getStore'])->getMock();
        $order->method('getEntityId')->willReturn($id);
        $order->method('getIncrementId')->willReturn($increment);
        $order->method('getStore')->willReturn($store);
        return $order;
    }
}
