<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Observer;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * sales_order_save_commit_after: the order's transaction has committed.
 * Any obligation the order still owes is registered now, in the
 * registration's own locked transaction: the first time right after the
 * create, and again on every later save (an invoice, a shipment) while
 * it stays owed. A failure is logged and leaves the obligation owed; it
 * never fails the save that triggered it.
 */
class RegisterAfterCommitObserver implements ObserverInterface
{
    public function __construct(
        private readonly StockRegistrationInterface $registration,
        private readonly ObligationResource $obligations,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Registers what the saved order still owes, after its commit; a failure is logged and never fails the save.
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface || !$order->getEntityId()) {
            return;
        }
        $orderId = (int) $order->getEntityId();
        if (!$this->owesAnything($orderId)) {
            return;
        }
        try {
            $this->registration->register($orderId);
        } catch (\Throwable $e) {
            // the registration logged the failure itself
            $this->logger->debug('Stock registration after the order save failed; the obligation stays owed', [
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether any of the order's obligations is still owed.
     */
    private function owesAnything(int $orderId): bool
    {
        foreach ($this->obligations->forOrder($orderId) as $obligation) {
            if ($obligation->isOwed()) {
                return true;
            }
        }
        return false;
    }
}
