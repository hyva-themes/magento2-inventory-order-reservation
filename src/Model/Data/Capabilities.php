<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Data;

use Hyva\InventoryOrderReservation\Api\Data\CapabilitiesInterface;

class Capabilities implements CapabilitiesInterface
{
    public function __construct(
        private readonly string $version,
        private readonly string $mode,
        private readonly string $attribute = 'reserve_stock'
    ) {
    }

    /**
     * The module's version.
     *
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * "inventory" or "legacy".
     *
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * The extension attribute to send: "reserve_stock".
     *
     */
    public function getAttribute(): string
    {
        return $this->attribute;
    }
}
