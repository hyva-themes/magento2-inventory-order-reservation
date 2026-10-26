<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Hyva\InventoryOrderReservation\Api\CapabilitiesInterface;
use Hyva\InventoryOrderReservation\Api\Data\CapabilitiesInterface as Capabilities;
use Hyva\InventoryOrderReservation\Model\Data\CapabilitiesFactory;

class CapabilitiesService implements CapabilitiesInterface
{
    /**
     * The release this source is at; the composer package's version for
     * a client that compares.
     */
    public const VERSION = '1.0.0';

    /**
     * Constructor.
     * @param CapabilitiesFactory $capabilitiesFactory
     * @param OptionalInventory $inventory
     */
    public function __construct(
        private readonly CapabilitiesFactory $capabilitiesFactory,
        private readonly OptionalInventory $inventory
    ) {
    }

    /**
     * The module's version, the store's mode and the attribute to send.
     *
     * @return Capabilities
     */
    public function get(): Capabilities
    {
        return $this->capabilitiesFactory->create([
            'version' => self::VERSION,
            'mode' => $this->inventory->isInstalled() ? Obligation::MODE_INVENTORY : Obligation::MODE_LEGACY,
        ]);
    }
}
