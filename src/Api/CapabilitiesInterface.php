<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Api;

use Hyva\InventoryOrderReservation\Api\Data\CapabilitiesInterface as Capabilities;

/**
 * GET /V1/inventory/order-reservation
 */
interface CapabilitiesInterface
{
    /**
     * The module's capabilities on this store.
     * @return \Hyva\InventoryOrderReservation\Api\Data\CapabilitiesInterface
     */
    public function get(): Capabilities;
}
