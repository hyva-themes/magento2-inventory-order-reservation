<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Hyva\InventoryOrderReservation\Model\ResourceModel\WebsiteStock;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * Whether registration can run on this store as installed, apart from
 * its mode: the Inventory modules the handler package reads are enabled,
 * the mode's handler is installed, and, for a website, the website sells
 * from a stock. The capabilities route, the admin message, the listing
 * and the registration read the same answer.
 */
class Readiness
{
    public const HANDLER_PACKAGE = 'Hyva_InventoryOrderReservationMsi';

    /**
     * @param \ArrayAccess<string, Handler\HandlerInterface> $handlers the handlers by mode, as installed
     */
    public function __construct(
        private readonly InventoryPresence $presence,
        private readonly \ArrayAccess $handlers,
        private readonly WebsiteStock $websiteStock,
        private readonly WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    /**
     * Why registration cannot run, as installed, or null when it can; with a website, whether that website sells from a stock.
     */
    public function problem(?int $websiteId = null): ?string
    {
        $missing = $this->presence->missingModules();
        if ($missing !== []) {
            return (string) __('Magento Inventory is enabled, but these of its modules are disabled or missing: %1.', implode(', ', $missing));
        }
        $mode = $this->presence->mode();
        if (!isset($this->handlers[$mode])) {
            return $mode === Obligation::MODE_INVENTORY
                ? (string) __('Magento Inventory is enabled, but %1, which places the reservation, is not installed.', self::HANDLER_PACKAGE)
                : (string) __('No stock registration handler for mode "%1" is installed.', $mode);
        }
        if ($websiteId !== null && $mode === Obligation::MODE_INVENTORY) {
            $code = (string) $this->websiteRepository->getById($websiteId)->getCode();
            if ($this->websiteStock->idForWebsiteCode($code) === null) {
                return (string) __('Website "%1" is assigned to no stock.', $code);
            }
        }
        return null;
    }

    public function isReady(?int $websiteId = null): bool
    {
        return $this->problem($websiteId) === null;
    }
}
