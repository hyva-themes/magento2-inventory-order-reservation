<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Plugin\Inventory;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\InventoryReservationCli\Model\GetSalableQuantityInconsistencies;

/**
 * The inconsistency listing is diagnostic. An order whose registration
 * this module still owes is not an inconsistency for an administrator to
 * compensate; it is this module's to register (the cron, the command, the
 * admin message). It is left out of the listing so that the operator
 * workflow (list, then compensate) never compensates it.
 */
class LeaveOwedOrdersOutOfInconsistenciesPlugin
{
    /**
     * Constructor.
     * @param ObligationResource $obligations
     */
    public function __construct(
        private readonly ObligationResource $obligations
    ) {
    }

    /** @param GetSalableQuantityInconsistencies $subject
     * @param \Generator $result
     * @return \Generator
     */
    public function afterExecute(GetSalableQuantityInconsistencies $subject, \Generator $result): \Generator
    {
        foreach ($result as $bunch) {
            $kept = [];
            foreach ((array) $bunch as $key => $inconsistency) {
                if ($this->isOwed($inconsistency)) {
                    continue;
                }
                $kept[$key] = $inconsistency;
            }
            yield $kept;
        }
    }

    /**
     * Whether the inconsistency's order still owes its registration.
     * @param object $inconsistency
     * @return bool
     */
    private function isOwed(object $inconsistency): bool
    {
        if (!method_exists($inconsistency, 'getObjectId')) {
            return false;
        }
        foreach ($this->obligations->forOrder((int) $inconsistency->getObjectId()) as $obligation) {
            if ($obligation->isOwed()) {
                return true;
            }
        }
        return false;
    }
}
