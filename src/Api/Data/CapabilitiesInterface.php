<?php
/**
 * Hyvä Themes - https://hyva.io
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
    public const READY = 'ready';
    public const PROBLEM = 'problem';

    /**
     * The package's version as Composer installed it ("0.1.2", or "dev-main" for a branch install); "unknown" outside Composer.
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

    /**
     * Whether registration runs on this store as installed: the mode's handler is installed, Inventory's modules are enabled, the website sells from a stock.
     * @return bool
     */
    public function isReady(): bool;

    /**
     * Why it does not, or null.
     * @return string|null
     */
    public function getProblem(): ?string;
}
