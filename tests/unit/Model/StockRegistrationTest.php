<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\Handler\HandlerInterface;
use Hyva\InventoryOrderReservation\Model\Handler\InstallationProblemException;
use Hyva\InventoryOrderReservation\Model\Handler\RowOutcome;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\Readiness;
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
    public function test_an_owed_row_placed_by_the_handler_is_done_by_registration(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$owed]);
        $resource->expects(self::once())->method('markRowDone')->with(9, 'mug', Obligation::SETTLED_BY_REGISTRATION);
        $resource->expects(self::never())->method('markRowRefused');
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::once())->method('register')->with(self::isInstanceOf(OrderInterface::class), [$owed])
            ->willReturn([new RowOutcome($owed, RowOutcome::PLACED, -2.0)]);

        self::assertTrue($this->registration($resource, $handler)->register(9));
    }

    public function test_a_row_the_rows_already_balance_is_done_as_balanced(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$owed]);
        $resource->expects(self::once())->method('markRowDone')->with(9, 'mug', Obligation::SETTLED_BY_BALANCED);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('register')->willReturn([new RowOutcome($owed, RowOutcome::BALANCED)]);

        self::assertTrue($this->registration($resource, $handler)->register(9));
    }

    public function test_a_refused_row_is_marked_refused_with_its_reason_and_counts_no_attempt(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 3.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$owed]);
        $resource->expects(self::once())->method('markRowRefused')->with(9, 'mug', 'stock 2 holds +1.0000 past what the order owes');
        $resource->expects(self::never())->method('markRowDone');
        $resource->expects(self::never())->method('recordFailure');
        $resource->expects(self::never())->method('markRefused');
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('register')->willReturn([new RowOutcome($owed, RowOutcome::REFUSED, reason: 'stock 2 holds +1.0000 past what the order owes', remainders: [2 => 1.0])]);

        self::assertTrue($this->registration($resource, $handler)->register(9), 'the run completed; the row is a result, not a failure');
    }

    public function test_rows_of_one_order_end_in_their_own_states_in_one_run(): void
    {
        $cup = new Obligation(9, 'cup', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $mug = new Obligation(9, 'mug', 'simple', 3.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$cup, $mug]);
        $resource->expects(self::once())->method('markRowDone')->with(9, 'cup', Obligation::SETTLED_BY_REGISTRATION);
        $resource->expects(self::once())->method('markRowRefused')->with(9, 'mug', 'surplus');
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('register')->willReturn([new RowOutcome($cup, RowOutcome::PLACED, -2.0), new RowOutcome($mug, RowOutcome::REFUSED, reason: 'surplus')]);

        self::assertTrue($this->registration($resource, $handler)->register(9));
    }

    public function test_a_handler_that_answers_for_the_wrong_rows_fails_the_run(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$owed]);
        $resource->expects(self::never())->method('markRowDone');
        $resource->expects(self::once())->method('recordFailure')->with(9, self::stringContains('wrong rows'))->willReturn(1);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('register')->willReturn([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing: mug');
        $this->registration($resource, $handler)->register(9);
    }

    public function test_nothing_owed_means_nothing_written(): void
    {
        $resource = $this->resource([]);
        $resource->expects(self::never())->method('markRowDone');
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::never())->method('register');

        self::assertFalse($this->registration($resource, $handler)->register(9));
    }

    public function test_a_handler_failure_records_the_attempt_and_leaves_the_obligation_owed(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$owed]);
        $resource->expects(self::never())->method('markRowDone');
        $resource->expects(self::once())->method('recordFailure')->with(9, 'Inventory refused')->willReturn(1);
        $resource->expects(self::never())->method('markRefused');
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('register')->willThrowException(new LocalizedException(__('Inventory refused')));

        $this->expectException(LocalizedException::class);
        $this->registration($resource, $handler)->register(9);
    }

    public function test_a_database_failure_reaches_the_caller_without_its_text(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$owed]);
        $resource->expects(self::once())->method('recordFailure')->with(9, 'SQLSTATE[HY000]: the table is gone')->willReturn(1);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('register')->willThrowException(new \RuntimeException('SQLSTATE[HY000]: the table is gone'));

        try {
            $this->registration($resource, $handler)->register(9);
            self::fail('the failure must surface');
        } catch (LocalizedException $e) {
            self::assertStringNotContainsString('SQLSTATE', $e->getMessage());
            self::assertStringContainsString('inventory:order-reservation:list has the reason', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function test_the_last_allowed_failure_gives_the_order_up(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$owed]);
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
        $resource = $this->resource([$owed], oneConnection: false);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::never())->method('register');

        $this->expectExceptionMessage('one database connection');
        $this->registration($resource, $handler)->register(9);
    }

    public function test_an_installation_problem_leaves_the_order_owed_with_no_attempt_counted(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY);
        $resource = $this->resource([$owed]);
        $resource->expects(self::never())->method('recordFailure');
        $resource->expects(self::never())->method('markRefused');
        $resource->expects(self::never())->method('markRowDone');
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::never())->method('register');

        $this->expectException(InstallationProblemException::class);
        $this->expectExceptionMessage('Hyva_InventoryOrderReservationMsi, which places the reservation, is not installed');
        $this->registration($resource, $handler, problem: 'Magento Inventory is enabled, but Hyva_InventoryOrderReservationMsi, which places the reservation, is not installed.')->register(9);
    }

    public function test_a_mode_without_a_handler_is_a_failure_not_a_legacy_deduction(): void
    {
        $owed = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, 'held');
        $resource = $this->resource([$owed]);
        $resource->expects(self::once())->method('recordFailure')->willReturn(1);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::never())->method('register');

        $this->expectExceptionMessage('No stock registration handler for mode "held"');
        $this->registration($resource, $handler)->register(9);
    }

    /**
     * The order's rows as the resource answers them under the lock.
     *
     * @param Obligation[] $owed
     */
    private function resource(array $owed, bool $oneConnection = true): ObligationResource
    {
        $resource = $this->createMock(ObligationResource::class);
        $resource->method('owedForOrder')->with(9)->willReturn($owed);
        $resource->method('isOneConnection')->willReturn($oneConnection);
        return $resource;
    }

    /**
     * The handlers keyed by mode, as the lazy map hands them out.
     *
     * @param array<string, HandlerInterface> $handlers
     */
    private function handlers(array $handlers): \ArrayAccess
    {
        return new class ($handlers) implements \ArrayAccess {
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
    }

    private function registration(ObligationResource $resource, HandlerInterface $handler, ?string $problem = null): StockRegistration
    {
        $readiness = $this->createMock(Readiness::class);
        $readiness->method('problem')->willReturn($problem);
        $mutex = $this->createMock(OrderMutexInterface::class);
        $mutex->method('execute')->willReturnCallback(static fn (int $orderId, callable $callable, array $args) => $callable(...$args));
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($this->createMock(OrderInterface::class));
        return new StockRegistration(
            $mutex,
            $resource,
            $orders,
            $this->createMock(LoggerInterface::class),
            $this->handlers([Obligation::MODE_INVENTORY => $handler]),
            $readiness
        );
    }
}
