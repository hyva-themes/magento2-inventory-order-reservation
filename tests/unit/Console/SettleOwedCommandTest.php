<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Console;

use Hyva\InventoryOrderReservation\Console\Command\SettleOwedCommand;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\Console\Cli;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * Settling by hand closes the rows under the order's lock and writes no stock.
 */
class SettleOwedCommandTest extends TestCase
{
    public function test_each_named_order_is_settled_under_its_lock(): void
    {
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->expects(self::exactly(2))->method('markOrderSettledByHand')
            ->willReturnCallback(static fn (int $orderId): int => $orderId === 7 ? 2 : 0);
        $locked = [];
        $mutex = $this->createStub(OrderMutexInterface::class);
        $mutex->method('execute')->willReturnCallback(static function (int $orderId, callable $callable, array $args) use (&$locked) {
            $locked[] = $orderId;
            return $callable(...$args);
        });
        $tester = new CommandTester(new SettleOwedCommand($obligations, $mutex));

        self::assertSame(Cli::RETURN_SUCCESS, $tester->execute(['order-id' => ['7', '8']]));
        self::assertSame([7, 8], $locked);
        self::assertStringContainsString('order 7: 2 row(s) settled by hand, no stock written', $tester->getDisplay());
        self::assertStringContainsString('order 8: nothing to settle', $tester->getDisplay());
    }

    public function test_a_failure_is_reported_and_exits_non_zero(): void
    {
        $mutex = $this->createStub(OrderMutexInterface::class);
        $mutex->method('execute')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $tester = new CommandTester(new SettleOwedCommand($this->createStub(ObligationResource::class), $mutex));

        self::assertSame(Cli::RETURN_FAILURE, $tester->execute(['order-id' => ['7']]));
        self::assertStringContainsString('order 7: Lock wait timeout', $tester->getDisplay());
    }
}
