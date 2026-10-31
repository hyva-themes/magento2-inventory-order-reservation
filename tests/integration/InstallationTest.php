<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

use Hyva\InventoryOrderReservation\Api\CapabilitiesInterface;
use Hyva\InventoryOrderReservation\Model\Handler\InstallationProblemException;
use Hyva\InventoryOrderReservation\Model\OwedMessage;
use Hyva\InventoryOrderReservation\Model\Readiness;
use Hyva\InventoryOrderReservation\Model\StockRegistration;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * A missing handler package is an installation problem, not an order's
 * failure: orders sent with the flag are accepted and stay owed with no
 * attempt counted, the capabilities route, the admin message and the
 * listing name the installation, and the first registration after the
 * fix completes them with what remains.
 */
class InstallationTest extends StoreCase
{
    public function test_without_the_modes_handler_orders_wait_and_the_store_names_the_installation(): void
    {
        $inventory = $this->inventoryInstalled();
        $stockBefore = $inventory ? null : $this->legacyQty();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);

        // the store as if the handler package were not installed: the mode has no handler
        $readiness = self::$objectManager->create(Readiness::class, ['handlers' => $this->noHandlers()]);
        $problem = (string) $readiness->problem();
        self::assertSame(
            $inventory
                ? 'Magento Inventory is enabled, but Hyva_InventoryOrderReservationMsi, which places the reservation, is not installed.'
                : 'No stock registration handler for mode "legacy" is installed.',
            $problem
        );

        /** @var StockRegistration $registration */
        $registration = self::$objectManager->create(StockRegistration::class, ['handlers' => $this->noHandlers(), 'readiness' => $readiness]);
        try {
            $registration->register($orderId);
            self::fail('an installation problem must surface');
        } catch (InstallationProblemException $e) {
            self::assertSame($problem, $e->getMessage());
        }
        self::assertSame('owed', $this->obligationStatus($orderId), 'the order waits');
        self::assertSame(0, $this->obligationAttempts($orderId), 'not the order\'s failure: no attempt counted');
        self::assertNull($this->obligations($orderId)[0]->lastError);
        if ($inventory) {
            self::assertSame([], $this->reservationRows($orderId), 'nothing placed');
        } else {
            self::assertSame($stockBefore, $this->legacyQty(), 'nothing deducted');
        }

        // the capabilities route and the admin message name it
        $capabilities = self::$objectManager->create(CapabilitiesInterface::class, ['readiness' => $readiness])->get();
        self::assertFalse($capabilities->isReady());
        self::assertSame($problem, $capabilities->getProblem());
        self::assertSame('handler_missing', $capabilities->getProblemCode());
        /** @var OwedMessage $message */
        $message = self::$objectManager->create(OwedMessage::class, ['readiness' => $readiness]);
        self::assertTrue($message->isDisplayed(), 'named at once, not after an hour');
        self::assertStringContainsString($problem, $message->getText());

        // as installed: ready, and the first registration completes the order
        self::assertTrue(self::$objectManager->get(Readiness::class)->isReady($this->websiteId()));
        $ready = self::$objectManager->get(CapabilitiesInterface::class)->get();
        self::assertTrue($ready->isReady());
        self::assertNull($ready->getProblemCode());
        self::assertTrue($this->register($orderId));
        self::assertSame('done', $this->obligationStatus($orderId));
    }

    /**
     * A handler map with nothing in it.
     */
    private function noHandlers(): \ArrayAccess
    {
        return new class implements \ArrayAccess {
            public function offsetExists(mixed $offset): bool
            {
                return false;
            }

            public function offsetGet(mixed $offset): mixed
            {
                return null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };
    }

    private function websiteId(): int
    {
        $connection = $this->connection();
        return (int) $connection->fetchOne(
            $connection->select()->from($this->table('store_website'), ['website_id'])->where('code = ?', self::WEBSITE_CODE)
        );
    }
}
