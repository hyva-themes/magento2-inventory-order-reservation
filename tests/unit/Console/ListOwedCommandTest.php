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
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\Readiness;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * A refused row names the command that closes it for the store's mode, and
 * the note under a cut listing names only the list that was cut.
 */
class ListOwedCommandTest extends TestCase
{
    public function test_a_refused_inventory_row_on_an_inventory_store_points_to_a_compensation(): void
    {
        $display = $this->listRefused(Obligation::MODE_INVENTORY, true);

        self::assertStringContainsString('a compensation repairs it', $display);
        self::assertStringNotContainsString('inventory:order-reservation:settle', $display);
    }

    public function test_a_refused_legacy_row_points_to_settle(): void
    {
        $display = $this->listRefused(Obligation::MODE_LEGACY, false);

        self::assertStringContainsString('inventory:order-reservation:settle 7 closes it once the stock is corrected by hand', $display);
        self::assertStringNotContainsString('a compensation repairs it', $display);
    }

    public function test_a_refused_inventory_row_after_inventory_was_disabled_points_to_settle(): void
    {
        $display = $this->listRefused(Obligation::MODE_INVENTORY, false);

        self::assertStringContainsString('inventory:order-reservation:settle 7 closes it once the stock is corrected by hand', $display);
        self::assertStringNotContainsString('a compensation repairs it', $display);
    }

    public function test_a_refused_legacy_row_after_inventory_was_enabled_points_to_settle(): void
    {
        $display = $this->listRefused(Obligation::MODE_LEGACY, true);

        self::assertStringContainsString('inventory:order-reservation:settle 7 closes it once the stock is corrected by hand', $display);
        self::assertStringNotContainsString('a compensation repairs it', $display);
    }

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

    private function listRefused(string $rowMode, bool $storeRunsInventory): string
    {
        $obligations = $this->createStub(ObligationResource::class);
        $obligations->method('orderIdsOwed')->willReturn([]);
        $obligations->method('orderIdsRefused')->willReturn([7]);
        $obligations->method('forOrder')->willReturn([
            new Obligation(7, 'SKU-1', 'simple', 1.0, 1, 1, $rowMode, Obligation::STATUS_REFUSED, null, 0, 'the reason'),
        ]);
        return $this->display($obligations, $storeRunsInventory);
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
