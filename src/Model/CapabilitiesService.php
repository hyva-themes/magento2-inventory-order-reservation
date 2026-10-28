<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Hyva\InventoryOrderReservation\Api\CapabilitiesInterface;
use Hyva\InventoryOrderReservation\Api\Data\CapabilitiesInterface as Capabilities;
use Hyva\InventoryOrderReservation\Model\Data\CapabilitiesFactory;
use Magento\Store\Model\StoreManagerInterface;

class CapabilitiesService implements CapabilitiesInterface
{
    /**
     * The release this source is at, the only place the version is kept:
     * the composer package takes its version from the git tag, so this
     * changes with every release.
     */
    public const VERSION = '0.1.0';

    public function __construct(
        private readonly CapabilitiesFactory $capabilitiesFactory,
        private readonly InventoryPresence $inventory,
        private readonly Readiness $readiness,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The module's version, the store's mode, the attribute to send, and whether registration runs for the request's website.
     */
    public function get(): Capabilities
    {
        $problem = $this->readiness->problem((int) $this->storeManager->getStore()->getWebsiteId());
        return $this->capabilitiesFactory->create([
            'version' => self::VERSION,
            'mode' => $this->inventory->mode(),
            'ready' => $problem === null,
            'problem' => $problem,
        ]);
    }
}
