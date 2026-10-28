<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\InventoryPresence;
use Hyva\InventoryOrderReservation\Model\OwedMessage;
use Hyva\InventoryOrderReservation\Model\Readiness;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * Refused orders are shown at once, with the compensation command only on
 * a store that runs Inventory.
 */
class OwedMessageTest extends TestCase
{
    public function test_refused_orders_on_an_inventory_store_name_the_compensation_and_settle(): void
    {
        $message = $this->message(storeRunsInventory: true);

        self::assertTrue($message->isDisplayed());
        $text = $message->getText();
        self::assertStringStartsWith('The stock registration of 2 order(s) created through the API was refused, and 3 still owe it.', $text);
        self::assertStringContainsString('inventory:reservation:create-compensations', $text);
        self::assertStringContainsString('inventory:order-reservation:settle <order id>', $text);
    }

    public function test_refused_orders_without_inventory_name_settle_and_no_compensation(): void
    {
        $message = $this->message(storeRunsInventory: false);

        self::assertTrue($message->isDisplayed());
        $text = $message->getText();
        self::assertStringStartsWith('The stock registration of 2 order(s) created through the API was refused, and 3 still owe it.', $text);
        self::assertStringNotContainsString('compensation', $text);
        self::assertStringContainsString('inventory:order-reservation:settle <order id>', $text);
    }

    private function message(bool $storeRunsInventory): OwedMessage
    {
        $obligations = $this->createStub(ObligationResource::class);
        // owed only minutes ago: the refusal alone shows the message
        $obligations->method('owedSummary')->willReturn(['count' => 3, 'oldest' => gmdate('Y-m-d H:i:s'), 'refused' => 2]);
        $readiness = $this->createStub(Readiness::class);
        $readiness->method('problem')->willReturn(null);
        $inventory = $this->createStub(InventoryPresence::class);
        $inventory->method('isInventory')->willReturn($storeRunsInventory);
        return new OwedMessage($obligations, $readiness, $inventory);
    }
}
