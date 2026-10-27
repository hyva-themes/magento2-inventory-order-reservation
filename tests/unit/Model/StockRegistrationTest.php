<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\Handler\HandlerInterface;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservation\Model\StockRegistration;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StockRegistrationTest extends TestCase
{
    public function test_an_owed_order_is_registered_once_under_the_lock_and_marked_done(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('owedForOrder')->with(9)->willReturn([$owed]);
        $resource->method('isOneConnection')->willReturn(true);
        $resource->expects(self::once())->method('markDone')->with(9, Obligation::SETTLED_BY_REGISTRATION);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::once())->method('register')->with(self::isInstanceOf(OrderInterface::class), [$owed]);
        $registration = $this->registration($resource, $handler);

        self::assertTrue($registration->register(9));
    }

    public function test_nothing_owed_means_nothing_written(): void
    {
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('owedForOrder')->willReturn([]);
        $resource->expects(self::never())->method('markDone');
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::never())->method('register');

        self::assertFalse($this->registration($resource, $handler)->register(9));
    }

    public function test_a_handler_failure_records_the_attempt_and_leaves_the_obligation_owed(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('owedForOrder')->willReturn([$owed]);
        $resource->method('isOneConnection')->willReturn(true);
        $resource->expects(self::never())->method('markDone');
        $resource->expects(self::once())->method('recordFailure')->with(9, 'Inventory refused')->willReturn(1);
        $resource->expects(self::never())->method('markRefused');
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('register')->willThrowException(new LocalizedException(__('Inventory refused')));

        $this->expectException(LocalizedException::class);
        $this->registration($resource, $handler)->register(9);
    }

    public function test_the_last_allowed_failure_gives_the_order_up(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('owedForOrder')->willReturn([$owed]);
        $resource->method('isOneConnection')->willReturn(true);
        $resource->method('recordFailure')->willReturn(Obligation::MAX_ATTEMPTS);
        $resource->expects(self::once())->method('markRefused')->with(9);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('register')->willThrowException(new LocalizedException(__('Some of the products are out of stock.')));
        $this->expectException(LocalizedException::class);
        $this->registration($resource, $handler)->register(9);
    }

    public function test_two_connections_refuse_the_step(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('owedForOrder')->willReturn([$owed]);
        $resource->method('isOneConnection')->willReturn(false);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::never())->method('register');

        $this->expectExceptionMessage('one database connection');
        $this->registration($resource, $handler)->register(9);
    }

    private function registration(ObligationResource $resource, HandlerInterface $handler): StockRegistration
    {
        $mutex = $this->createMock(OrderMutexInterface::class);
        $mutex->method('execute')->willReturnCallback(static fn (int $orderId, callable $callable, array $args) => $callable(...$args));
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($this->createMock(OrderInterface::class));
        return new StockRegistration(
            $mutex,
            $resource,
            $orders,
            $this->createMock(LoggerInterface::class),
            [Obligation::MODE_INVENTORY => $handler]
        );
    }
}
