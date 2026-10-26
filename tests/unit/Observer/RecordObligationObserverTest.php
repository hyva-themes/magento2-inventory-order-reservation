<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Observer;

use Hyva\InventoryOrderReservation\Model\ObligationRecorder;
use Hyva\InventoryOrderReservation\Observer\RecordObligationObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class RecordObligationObserverTest extends TestCase
{
    public function test_only_a_new_flagged_order_is_recorded(): void
    {
        $recorder = $this->createMock(ObligationRecorder::class);
        $recorder->method('isRequested')->willReturn(true);
        $recorder->method('isRecorded')->willReturn(false);
        $recorder->expects(self::once())->method('record');
        $observer = new RecordObligationObserver($recorder);

        $observer->execute($this->event($this->order(origEntityId: null)));
        $observer->execute($this->event($this->order(origEntityId: 41)));
    }

    public function test_a_second_save_of_the_same_new_order_records_nothing(): void
    {
        // the original data stays unset after a save, so the object reads
        // as new again when a plugin saves it a second time in the request
        $recorder = $this->createMock(ObligationRecorder::class);
        $recorder->method('isRequested')->willReturn(true);
        $recorder->method('isRecorded')->willReturn(true);
        $recorder->expects(self::never())->method('record');

        (new RecordObligationObserver($recorder))->execute($this->event($this->order(origEntityId: null)));
    }

    public function test_an_unflagged_order_is_left_alone(): void
    {
        $recorder = $this->createMock(ObligationRecorder::class);
        $recorder->method('isRequested')->willReturn(false);
        $recorder->expects(self::never())->method('record');

        (new RecordObligationObserver($recorder))->execute($this->event($this->order(origEntityId: null)));
    }

    private function order(?int $origEntityId): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getOrigData'])->getMock();
        $order->method('getOrigData')->with('entity_id')->willReturn($origEntityId);
        return $order;
    }

    private function event(Order $order): Observer
    {
        $event = new Event(['order' => $order]);
        return new Observer(['event' => $event]);
    }
}
