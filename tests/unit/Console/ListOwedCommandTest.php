<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Console;

use Hyva\InventoryOrderReservation\Console\Command\ListOwedCommand;
use Hyva\InventoryOrderReservation\Model\InventoryPresence;
use Hyva\InventoryOrderReservation\Model\Readiness;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * The note under a cut listing names only the list that was cut.
 */
class ListOwedCommandTest extends TestCase
{
    public function test_a_full_batch_is_not_called_cut(): void
    {
        $display = $this->listCounts(ObligationResource::BATCH, ObligationResource::BATCH);

        self::assertStringNotContainsString('Only the first', $display);
    }

    public function test_a_cut_owed_list_is_the_only_one_named(): void
    {
        $display = $this->listCounts(ObligationResource::BATCH + 1, 3);

        self::assertStringContainsString('Only the first 200 owed orders are listed.', $display);
        self::assertStringNotContainsString('refused orders are listed', $display);
    }

    public function test_a_cut_refused_list_is_the_only_one_named(): void
    {
        $display = $this->listCounts(3, ObligationResource::BATCH + 1);

        self::assertStringContainsString('Only the first 200 refused orders are listed.', $display);
        self::assertStringNotContainsString('owed orders are listed', $display);
    }

    public function test_both_cut_lists_are_named(): void
    {
        $display = $this->listCounts(ObligationResource::BATCH + 1, ObligationResource::BATCH + 1);

        self::assertStringContainsString('Only the first 200 owed and the first 200 refused orders are listed.', $display);
    }

    private function listCounts(int $owed, int $refused): string
    {
        $obligations = $this->createStub(ObligationResource::class);
        $obligations->method('orderIdsOwed')->willReturn(range(1, $owed));
        $obligations->method('orderIdsRefused')->willReturn(range(1001, 1000 + $refused));
        $obligations->method('owedForOrder')->willReturn([]);
        $obligations->method('forOrder')->willReturn([]);
        return $this->display($obligations, true);
    }

    private function display(ObligationResource $obligations, bool $storeRunsInventory): string
    {
        $inventory = $this->createStub(InventoryPresence::class);
        $inventory->method('isInventory')->willReturn($storeRunsInventory);
        $tester = new CommandTester(new ListOwedCommand($obligations, $this->createStub(Readiness::class), $inventory));
        $tester->execute([]);
        return $tester->getDisplay();
    }
}
