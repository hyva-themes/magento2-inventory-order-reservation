<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Data;

use Hyva\InventoryOrderReservation\Api\Data\CapabilitiesInterface;

class Capabilities implements CapabilitiesInterface
{
    /**
     * Constructor.
     * @param string $version
     * @param string $mode
     * @param string $attribute
     */
    public function __construct(
        private readonly string $version,
        private readonly string $mode,
        private readonly string $attribute = 'reserve_stock'
    ) {
    }

    /**
     * The module's version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * "inventory" or "legacy".
     *
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * The extension attribute to send: "reserve_stock".
     *
     * @return string
     */
    public function getAttribute(): string
    {
        return $this->attribute;
    }
}
