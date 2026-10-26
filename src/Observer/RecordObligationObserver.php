<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Observer;

use Hyva\InventoryOrderReservation\Model\ObligationRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * sales_order_save_after: fired by the order model inside its own save
 * transaction, before the commit. A new order that asks for its stock
 * side gets its obligation rows here, so they commit with the order or
 * not at all. Every later save of the order passes through untouched.
 *
 * "New" is read off the model's original data, which Magento sets on a
 * load and never on a save: an order object saved twice in one request
 * (a plugin that invoices it right after placement saves it again) reads
 * as new both times. The rows on record are the tiebreak, so the second
 * save records nothing instead of hitting the unique key and failing the
 * caller's transaction.
 */
class RecordObligationObserver implements ObserverInterface
{
    /**
     * Constructor.
     * @param ObligationRecorder $recorder
     */
    public function __construct(
        private readonly ObligationRecorder $recorder
    ) {
    }

    /**
     * Records the obligation of a new order that asks for it; every other save passes.
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface || !$this->isNew($order) || !$this->recorder->isRequested($order)) {
            return;
        }
        if ($this->recorder->isRecorded($order)) {
            return;
        }
        $this->recorder->record($order);
    }

    /**
     * An order without original data: inserted by this request, by this
     * save or an earlier one of the same object.
     */
    private function isNew(OrderInterface $order): bool
    {
        return method_exists($order, 'getOrigData') && $order->getOrigData('entity_id') === null;
    }
}
