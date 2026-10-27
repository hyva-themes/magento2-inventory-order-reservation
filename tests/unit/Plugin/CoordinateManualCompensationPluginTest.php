<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Plugin;

use Hyva\InventoryOrderReservation\Model\Compensation\CoordinatedCompensation;
use Hyva\InventoryOrderReservation\Model\Compensation\Outcome;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservation\Plugin\Inventory\CoordinateManualCompensationPlugin;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use PHPUnit\Framework\TestCase;

class CoordinateManualCompensationPluginTest extends TestCase
{
    protected function setUp(): void
    {
        // the plugin sits on Magento Inventory's reservation writer; a
        // Magento without Inventory (Open Source with the packages
        // removed) has nothing to mock here and runs the rest of the suite
        if (!interface_exists(AppendReservationsInterface::class)) {
            self::markTestSkipped('Magento Inventory is not installed in the borrowed vendor');
        }
    }

    public function test_rows_of_other_kinds_and_for_unknown_orders_pass_through(): void
    {
        $coordinated = $this->createMock(CoordinatedCompensation::class);
        $coordinated->expects(self::never())->method('apply');
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->method('forOrder')->willReturn([]);
        $plugin = new CoordinateManualCompensationPlugin($coordinated, $obligations, new Json());
        $passed = [];
        $proceed = static function (array $rows) use (&$passed): void {
            $passed = $rows;
        };
        $shipment = $this->reservation('{"event_type":"shipment_created","object_type":"order","object_id":"9"}');
        $manualForUnknown = $this->reservation('{"event_type":"manual_compensation","object_type":"order","object_id":77}');

        $plugin->aroundExecute($this->createMock(AppendReservationsInterface::class), $proceed, [$shipment, $manualForUnknown]);

        self::assertSame([$shipment, $manualForUnknown], $passed);
    }

    public function test_a_manual_compensation_for_an_owed_order_is_coordinated_and_a_refusal_is_an_exception(): void
    {
        $coordinated = $this->createMock(CoordinatedCompensation::class);
        $coordinated->expects(self::once())->method('apply')->with(9, self::anything())
            ->willReturn(new Outcome(Outcome::REFUSED, 'mug', 2, -2.0, -1.0, -1.0, true, 'more than remains'));
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->method('forOrder')->with(9)->willReturn([new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY)]);
        $plugin = new CoordinateManualCompensationPlugin($coordinated, $obligations, new Json());
        $proceedCalled = false;
        $proceed = static function () use (&$proceedCalled): void {
            $proceedCalled = true;
        };

        try {
            $plugin->aroundExecute(
                $this->createMock(AppendReservationsInterface::class),
                $proceed,
                [$this->reservation('{"event_type":"manual_compensation","object_type":"order","object_id":9}')]
            );
            self::fail('a refusal must throw');
        } catch (CouldNotSaveException $e) {
            self::assertStringContainsString('more than remains', $e->getMessage());
        }
        self::assertFalse($proceedCalled, 'nothing else to write');
    }

    private function reservation(string $metadata): ReservationInterface
    {
        $reservation = $this->createMock(ReservationInterface::class);
        $reservation->method('getMetadata')->willReturn($metadata);
        $reservation->method('getSku')->willReturn('mug');
        $reservation->method('getStockId')->willReturn(2);
        $reservation->method('getQuantity')->willReturn(-1.0);
        return $reservation;
    }
}
