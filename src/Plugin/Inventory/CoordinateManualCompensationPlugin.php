<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Plugin\Inventory;

use Hyva\InventoryOrderReservation\Model\Compensation\CoordinatedCompensation;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;

/**
 * Every manual compensation that names an order reaches the coordinated
 * service, whichever code appends it (Magento's command, a store's own
 * integration). Rows of other kinds, and rows for orders this module has
 * no obligation for, pass through untouched. A refused compensation is a
 * CouldNotSaveException naming the discrepancy, so a caller that reads
 * the writer's exceptions learns why.
 */
class CoordinateManualCompensationPlugin
{
    public function __construct(
        private readonly CoordinatedCompensation $coordinated,
        private readonly ObligationResource $obligations,
        private readonly SerializerInterface $serializer
    ) {
    }

    /** @param AppendReservationsInterface $subject
     * @param array<int, object> $reservations
     * @throws CouldNotSaveException
     */
    public function aroundExecute(AppendReservationsInterface $subject, callable $proceed, array $reservations): void
    {
        if ($this->coordinated->isWriting()) {
            $proceed($reservations);
            return;
        }
        $passThrough = [];
        $coordinated = [];
        foreach ($reservations as $reservation) {
            $orderId = $this->coordinatedOrderId($reservation);
            if ($orderId === null) {
                $passThrough[] = $reservation;
            } else {
                $coordinated[] = [$orderId, $reservation];
            }
        }
        // the rows this module has no say in go first, in one write; each
        // coordinated row is then a whole write of its own under the order's
        // lock, so a refusal leaves only complete writes behind it
        if ($passThrough !== []) {
            $proceed($passThrough);
        }
        foreach ($coordinated as [$orderId, $reservation]) {
            try {
                $outcome = $this->coordinated->apply($orderId, $reservation);
            } catch (\Throwable $e) {
                throw new CouldNotSaveException(__(
                    'Compensation for order %1 refused: its stock registration could not complete (%2).',
                    $orderId,
                    $e->getMessage()
                ), $e);
            }
            if ($outcome->isRefused()) {
                throw new CouldNotSaveException(__('Compensation for order %1 %2', $orderId, $outcome->describe()));
            }
        }
    }

    /**
     * The order a manual compensation names, when this module holds an
     * obligation for it; null for every other row.
     */
    private function coordinatedOrderId(object $reservation): ?int
    {
        $metadata = $reservation->getMetadata();
        if (!is_string($metadata) || $metadata === '') {
            return null;
        }
        try {
            $data = $this->serializer->unserialize($metadata);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['event_type'] ?? '') !== 'manual_compensation' || ($data['object_type'] ?? '') !== 'order') {
            return null;
        }
        $orderId = (int) ($data['object_id'] ?? 0);
        if ($orderId <= 0 || $this->obligations->forOrder($orderId) === []) {
            return null;
        }
        return $orderId;
    }
}
