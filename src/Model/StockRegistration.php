<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Model\Handler\HandlerInterface;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;

/**
 * Exactly once, under the order's lock.
 *
 * `OrderMutex::execute` opens a transaction on the sales connection and
 * locks the order row (SELECT … FOR UPDATE), so two runners for one order
 * are serialized: the second reads the rows after the first committed and
 * finds nothing owed. The handler's inventory write and the completion
 * marker are in that one transaction; a failure anywhere rolls both back
 * and the obligation stays owed. That holds only when the sales
 * connection and the default one are the same adapter, which the
 * registration checks first and refuses otherwise.
 */
class StockRegistration implements StockRegistrationInterface
{
    /** @param HandlerInterface[] $handlers keyed by Obligation::MODE_*
     */
    public function __construct(
        private readonly OrderMutexInterface $orderMutex,
        private readonly ObligationResource $obligations,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly array $handlers = []
    ) {
    }

    /**
     * Registers what the order still owes under its lock; records a failed attempt outside the rolled-back transaction and rethrows.
     * @return bool true when this call wrote the stock
     * @throws LocalizedException
     */
    public function register(int $orderId): bool
    {
        try {
            return (bool) $this->orderMutex->execute($orderId, [$this, 'registerLocked'], [$orderId]);
        } catch (\Throwable $e) {
            // outside the rolled-back transaction: the attempt and its
            // reason, for the admin message and the console listing
            $attempts = $this->obligations->recordFailure($orderId, $e->getMessage());
            $context = ['order_id' => $orderId, 'attempts' => $attempts, 'exception' => $e->getMessage()];
            if ($attempts >= Obligation::MAX_ATTEMPTS) {
                // given up: Magento's inconsistency listing shows the order
                // again and a compensation settles it; `run <id>` tries once more
                $this->obligations->markRefused($orderId);
                $this->logger->warning('Stock registration gave up on order after repeated failures', $context);
            } elseif ($attempts <= 1) {
                $this->logger->warning('Stock registration failed for order; it stays owed', $context);
            } else {
                $this->logger->info('Stock registration failed again for order; it stays owed', $context);
            }
            throw $e instanceof LocalizedException ? $e : new LocalizedException(__('%1', $e->getMessage()), $e);
        }
    }

    /**
     * The locked part: runs inside the mutex's transaction.
     * @throws LocalizedException
     */
    public function registerLocked(int $orderId): bool
    {
        $owed = array_values(array_filter(
            $this->obligations->forOrder($orderId, forUpdate: true),
            static fn (Obligation $obligation): bool => $obligation->isOwed()
        ));
        if ($owed === []) {
            return false;
        }
        if (!$this->obligations->isOneConnection()) {
            throw new LocalizedException(__(
                'Stock registration needs the sales tables and the inventory tables on one database connection; this store keeps them apart.'
            ));
        }
        $mode = $owed[0]->mode;
        $handler = $this->handlers[$mode] ?? null;
        if (!$handler instanceof HandlerInterface) {
            throw new LocalizedException(__('No stock registration handler for mode "%1".', $mode));
        }
        $order = $this->orderRepository->get($orderId);
        $handler->register($order, $owed);
        $this->obligations->markDone($orderId, Obligation::SETTLED_BY_REGISTRATION);
        return true;
    }
}
