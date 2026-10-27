<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Api\Data;

/**
 * What a client learns before it sends `reserve_stock`: that the module
 * is there, which mode the store runs, and the module's version.
 */
interface CapabilitiesInterface
{
    public const VERSION = 'version';
    public const MODE = 'mode';
    public const ATTRIBUTE = 'attribute';

    /**
     * The module's version, as its composer package declares it.
     * @return string
     */
    public function getVersion(): string;

    /**
     * "inventory" on a store with Magento Inventory, "legacy" without it.
     * @return string
     */
    public function getMode(): string;

    /**
     * The extension attribute to send on the order: "reserve_stock".
     * @return string
     */
    public function getAttribute(): string;
}
