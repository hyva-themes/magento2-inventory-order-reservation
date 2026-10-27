<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Observer;

use Hyva\InventoryOrderReservation\Model\ObligationRecorder;
use Hyva\InventoryOrderReservation\Observer\MarkNewOrderObserver;
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

        $observer->execute($this->event($this->order(new: true)));
        $observer->execute($this->event($this->order(new: false)));
    }

    public function test_a_second_save_of_the_same_new_order_records_nothing(): void
    {
        // the mark made before the first save stays on the object, so it
        // reads as new again when a plugin saves it a second time in the request
        $recorder = $this->createMock(ObligationRecorder::class);
        $recorder->method('isRequested')->willReturn(true);
        $recorder->method('isRecorded')->willReturn(true);
        $recorder->expects(self::never())->method('record');

        (new RecordObligationObserver($recorder))->execute($this->event($this->order(new: true)));
    }

    public function test_an_unflagged_order_is_left_alone(): void
    {
        $recorder = $this->createMock(ObligationRecorder::class);
        $recorder->method('isRequested')->willReturn(false);
        $recorder->expects(self::never())->method('record');

        (new RecordObligationObserver($recorder))->execute($this->event($this->order(new: true)));
    }

    private function order(bool $new): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getData'])->getMock();
        $order->method('getData')->with(MarkNewOrderObserver::NEW_ORDER)->willReturn($new);
        return $order;
    }

    private function event(Order $order): Observer
    {
        $event = new Event(['order' => $order]);
        return new Observer(['event' => $event]);
    }
}
