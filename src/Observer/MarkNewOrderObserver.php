<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Marks an order as new before it is saved, when it has no id yet. The
 * API builds an order from its payload without loading it, so after the
 * save an update of an existing order carries no original data either;
 * the mark made before the save is what tells the two apart.
 */
class MarkNewOrderObserver implements ObserverInterface
{
    public const NEW_ORDER = 'hyva_inventory_order_reservation_new';

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if ($order instanceof OrderInterface && method_exists($order, 'setData')) {
            $order->setData(self::NEW_ORDER, $order->getEntityId() === null);
        }
    }
}
