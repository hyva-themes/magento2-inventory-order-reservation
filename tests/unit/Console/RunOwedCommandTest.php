<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Console;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Console\Command\RunOwedCommand;
use Hyva\InventoryOrderReservation\Console\OrderIdArguments;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * A run for named orders reopens and registers them; a malformed id runs none.
 */
class RunOwedCommandTest extends TestCase
{
    public function test_a_named_order_is_reopened_and_registered(): void
    {
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->expects(self::once())->method('reopen')->with(7);
        $obligations->method('forOrder')->willReturn([]);
        $registration = $this->createMock(StockRegistrationInterface::class);
        $registration->expects(self::once())->method('register')->with(7)->willReturn(true);
        $tester = new CommandTester(new RunOwedCommand($obligations, $registration, $this->createStub(State::class), new OrderIdArguments()));

        self::assertSame(Cli::RETURN_SUCCESS, $tester->execute(['order-id' => ['7']]));
        self::assertStringContainsString('order 7: registered', $tester->getDisplay());
    }

    public function test_malformed_ids_run_nothing_even_beside_valid_ones(): void
    {
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->expects(self::never())->method('reopen');
        $obligations->expects(self::never())->method('orderIdsOwed');
        $registration = $this->createMock(StockRegistrationInterface::class);
        $registration->expects(self::never())->method('register');
        $tester = new CommandTester(new RunOwedCommand($obligations, $registration, $this->createStub(State::class), new OrderIdArguments()));

        self::assertSame(Cli::RETURN_FAILURE, $tester->execute(['order-id' => ['7', '42abc']]));
        self::assertStringContainsString('Not an order entity id: "42abc". Nothing was run.', $tester->getDisplay());
    }
}
