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
    public function __construct(
        private readonly CapabilitiesFactory $capabilitiesFactory,
        private readonly InventoryPresence $inventory,
        private readonly Readiness $readiness,
        private readonly StoreManagerInterface $storeManager,
        private readonly PackageVersion $packageVersion
    ) {
    }

    /**
     * The module's version, the store's mode, the attribute to send, and whether registration runs for the request's website.
     */
    public function get(): Capabilities
    {
        $diagnosis = $this->readiness->diagnosis((int) $this->storeManager->getStore()->getWebsiteId());
        return $this->capabilitiesFactory->create([
            'version' => $this->packageVersion->get(),
            'mode' => $this->inventory->mode(),
            'ready' => $diagnosis === null,
            'problem' => $diagnosis['problem'] ?? null,
            'problemCode' => $diagnosis['code'] ?? null,
        ]);
    }
}
