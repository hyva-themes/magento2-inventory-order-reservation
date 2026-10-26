<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Compensation;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservation\Model\StockRegistration;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;

/**
 * A manual compensation that names an order, under that order's lock:
 * the order's owed registration completes first, then the request is
 * read against what the order still owes by Magento's own rule.
 *
 * The request is held against the order's persisted obligation before
 * anything is calculated: a sku the order owes nothing on, an obligation
 * settled on the legacy stock item (no stock to compensate), or a stock
 * other than the one the obligation names, is refused outright, so a
 * compensation can never reach a stock the order is not registered on.
 * Then, on the obligation's stock: already balanced, nothing is written;
 * the right amount, or part of it toward balance, is written with the
 * remainder reported; the wrong direction or more than remains is
 * refused with the current discrepancy. A refusal never touches the
 * registration, which is complete on its own by then.
 */
class CoordinatedCompensation
{
    /** @var bool while the service itself writes, so its writer plugin passes the row through */
    private bool $writing = false;

    /**
     * Constructor.
     * @param OrderMutexInterface $orderMutex
     * @param StockRegistration $registration
     * @param OrderRepositoryInterface $orderRepository
     * @param RemainingInconsistency $remaining
     * @param OptionalInventory $inventory
     * @param ObligationResource $obligations
     */
    public function __construct(
        private readonly OrderMutexInterface $orderMutex,
        private readonly StockRegistration $registration,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RemainingInconsistency $remaining,
        private readonly OptionalInventory $inventory,
        private readonly ObligationResource $obligations
    ) {
    }

    /**
     * Whether the service is appending its own validated row right now, so the writer plugin lets it through.
     *
     * @return bool
     */
    public function isWriting(): bool
    {
        return $this->writing;
    }

    /** @param object $reservation a \Magento\InventoryReservationsApi\Model\ReservationInterface
     * @throws LocalizedException when the order's registration cannot complete; the compensation is then refused with that reason
     */
    public function apply(int $orderId, object $reservation): Outcome
    {
        return $this->orderMutex->execute($orderId, [$this, 'applyLocked'], [$orderId, $reservation]);
    }

    /**
     * The locked part.
     * @param object $reservation
     */
    public function applyLocked(int $orderId, object $reservation): Outcome
    {
        $ran = $this->registration->registerLocked($orderId);
        $order = $this->orderRepository->get($orderId);
        $sku = (string) $reservation->getSku();
        $stockId = (int) $reservation->getStockId();
        $requested = (float) $reservation->getQuantity();
        $obligation = $this->obligationFor($orderId, $sku);
        if ($obligation === null) {
            return new Outcome(Outcome::REFUSED, $sku, $stockId, $requested, 0.0, 0.0, $ran, 'the order owes nothing on this sku');
        }
        if ($obligation->mode !== Obligation::MODE_INVENTORY || $obligation->stockId === null) {
            return new Outcome(
                Outcome::REFUSED,
                $sku,
                $stockId,
                $requested,
                0.0,
                0.0,
                $ran,
                'the order\'s stock side is the legacy stock item, not a Magento Inventory stock'
            );
        }
        if ($obligation->stockId !== $stockId) {
            $own = $this->remaining->forOrderSku($order, $sku, $obligation->stockId);
            return new Outcome(
                Outcome::REFUSED,
                $sku,
                $stockId,
                $requested,
                $own['remaining'],
                $own['remaining'],
                $ran,
                sprintf('stock %d named, the order is registered on stock %d', $stockId, $obligation->stockId)
            );
        }
        $state = $this->remaining->forOrderSku($order, $sku, $obligation->stockId);
        $remaining = $state['remaining'];
        if (abs($remaining) < 0.00005) {
            return new Outcome(Outcome::ALREADY_BALANCED, $sku, $stockId, $requested, 0.0, 0.0, $ran);
        }
        if ($requested == 0.0 || ($requested < 0) !== ($remaining < 0)) {
            return new Outcome(Outcome::REFUSED, $sku, $stockId, $requested, $remaining, $remaining, $ran, 'wrong direction');
        }
        if (abs($requested) > abs($remaining) + 0.00005) {
            return new Outcome(Outcome::REFUSED, $sku, $stockId, $requested, $remaining, $remaining, $ran, 'more than remains');
        }
        $writer = $this->inventory->appendReservations();
        if ($writer === null) {
            throw new LocalizedException(__('Magento Inventory is not available on this store.'));
        }
        $this->writing = true;
        try {
            $writer->execute([$reservation]);
        } finally {
            $this->writing = false;
        }
        $after = round($remaining - $requested, 4);
        return new Outcome(
            abs($after) < 0.00005 ? Outcome::APPLIED : Outcome::PARTIAL,
            $sku,
            $stockId,
            $requested,
            $remaining,
            $after,
            $ran
        );
    }

    /**
     * The order's persisted obligation for the sku, the row the request is held against.
     *
     * @param int $orderId
     * @param string $sku
     * @return Obligation|null
     */
    private function obligationFor(int $orderId, string $sku): ?Obligation
    {
        foreach ($this->obligations->forOrder($orderId) as $obligation) {
            if ($obligation->sku === $sku) {
                return $obligation;
            }
        }
        return null;
    }
}
