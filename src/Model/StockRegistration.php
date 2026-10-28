<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Model\Handler\HandlerInterface;
use Hyva\InventoryOrderReservation\Model\Handler\InstallationProblemException;
use Hyva\InventoryOrderReservation\Model\Handler\RowOutcome;
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
    /**
     * @param \ArrayAccess<string, HandlerInterface> $handlers keyed by Obligation::MODE_*, each built on first use
     */
    public function __construct(
        private readonly OrderMutexInterface $orderMutex,
        private readonly ObligationResource $obligations,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly \ArrayAccess $handlers,
        private readonly Readiness $readiness,
        private readonly InventoryPresence $inventory
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
            return (bool) $this->orderMutex->execute($orderId, $this->registerLocked(...), [$orderId]);
        } catch (InstallationProblemException $e) {
            // not the order's failure: no attempt counted, no per-order log
            // line; the admin message and the listing name the installation
            throw $e;
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
            // a database or runtime failure's text stays in the log and in last_error, not in a message the web API would show
            throw $e instanceof LocalizedException
                ? $e
                : new LocalizedException(__('Stock registration for order %1 failed; inventory:order-reservation:list has the reason.', $orderId), $e);
        }
    }

    /**
     * The locked part: runs inside the mutex's transaction.
     * @throws LocalizedException
     */
    public function registerLocked(int $orderId): bool
    {
        $owed = $this->obligations->owedForOrder($orderId, forUpdate: true);
        if ($owed === []) {
            return false;
        }
        if (!$this->obligations->isOneConnection()) {
            throw new LocalizedException(__(
                'Stock registration needs the sales tables and the inventory tables on one database connection; this store keeps them apart.'
            ));
        }
        $problem = $this->readiness->problem();
        if ($problem !== null) {
            throw new InstallationProblemException(__('%1', $problem));
        }
        $mode = $owed[0]->mode;
        $storeMode = $this->inventory->mode();
        if ($mode !== $storeMode && in_array($mode, [Obligation::MODE_INVENTORY, Obligation::MODE_LEGACY], true)) {
            // recorded in one mode, registered in the other: the legacy
            // deduction is a no-op with Inventory enabled, and an Inventory
            // row has no handler without it. Nothing a retry fixes. A mode
            // the module does not know stays a failure (no handler) below.
            $reason = (string) __(
                'The order was recorded in %1 mode, but the store now runs in %2 mode; its stock cannot be registered as recorded. '
                . 'inventory:order-reservation:settle closes it once the stock is corrected by hand.',
                $mode,
                $storeMode
            );
            foreach ($owed as $row) {
                $this->obligations->markRowRefused($orderId, $row->sku, $reason);
            }
            return false;
        }
        $handler = $this->handlers[$mode] ?? null;
        if (!$handler instanceof HandlerInterface) {
            throw new LocalizedException(__('No stock registration handler for mode "%1".', $mode));
        }
        $order = $this->orderRepository->get($orderId);
        $outcomes = $this->outcomesPerRow($handler->register($order, $owed), $owed);
        // each row's state with the rows the handler wrote, in the one
        // transaction: a refusal is a result (nothing a placement can fix,
        // a compensation repairs it), not a failure, so no attempt is counted
        foreach ($outcomes as $outcome) {
            if ($outcome->isRefused()) {
                $this->obligations->markRowRefused($orderId, $outcome->row->sku, $outcome->reason);
            } else {
                $this->obligations->markRowDone($orderId, $outcome->row->sku, $outcome->settledBy());
            }
        }
        return true;
    }

    /**
     * The handler's answers keyed by sku, one for every owed row and none for a row it was not given.
     *
     * @param RowOutcome[] $outcomes
     * @param Obligation[] $owed
     * @return array<string, RowOutcome>
     * @throws LocalizedException when a row has no answer or an answer names no owed row
     */
    private function outcomesPerRow(array $outcomes, array $owed): array
    {
        $bySku = [];
        foreach ($outcomes as $outcome) {
            $bySku[$outcome->row->sku] = $outcome;
        }
        $owedSkus = array_map(static fn (Obligation $row): string => $row->sku, $owed);
        $missing = array_diff($owedSkus, array_keys($bySku));
        $extra = array_diff(array_keys($bySku), $owedSkus);
        if ($missing !== [] || $extra !== []) {
            throw new LocalizedException(__(
                'The stock registration handler answered for the wrong rows (missing: %1; unexpected: %2).',
                implode(', ', $missing) ?: 'none',
                implode(', ', $extra) ?: 'none'
            ));
        }
        return $bySku;
    }
}
