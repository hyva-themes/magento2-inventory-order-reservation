<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Observer;

use Hyva\InventoryOrderReservation\Observer\MarkNewOrderObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * New is decided before the save, where an update built from an API
 * payload already carries its id and a new order has none.
 */
class MarkNewOrderObserverTest extends TestCase
{
    public function test_an_order_without_an_id_is_marked_new_and_one_with_an_id_is_not(): void
    {
        foreach ([[null, true], [41, false]] as [$entityId, $expected]) {
            $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getEntityId', 'setData'])->getMock();
            $order->method('getEntityId')->willReturn($entityId);
            $order->expects(self::once())->method('setData')->with(MarkNewOrderObserver::NEW_ORDER, $expected);
            (new MarkNewOrderObserver())->execute(new Observer(['event' => new Event(['order' => $order])]));
        }
    }
}
