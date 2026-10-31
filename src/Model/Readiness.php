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
     * Stable names for what is missing, beside the sentence: a client
     * keys on these, never on the wording.
     */
    public const PROBLEM_INVENTORY_MODULES_MISSING = 'inventory_modules_missing';
    public const PROBLEM_HANDLER_MISSING = 'handler_missing';
    public const PROBLEM_WEBSITE_WITHOUT_STOCK = 'website_without_stock';

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
        return $this->diagnosis($websiteId)['problem'] ?? null;
    }

    /**
     * What is missing, as a code (one of the `PROBLEM_*` constants) and the sentence `problem()` answers, or null when registration can run.
     *
     * @return array{code: string, problem: string}|null
     */
    public function diagnosis(?int $websiteId = null): ?array
    {
        $missing = $this->presence->missingModules();
        if ($missing !== []) {
            return [
                'code' => self::PROBLEM_INVENTORY_MODULES_MISSING,
                'problem' => (string) __('Magento Inventory is enabled, but these of its modules are disabled or missing: %1.', implode(', ', $missing)),
            ];
        }
        $mode = $this->presence->mode();
        if (!isset($this->handlers[$mode])) {
            return [
                'code' => self::PROBLEM_HANDLER_MISSING,
                'problem' => $mode === Obligation::MODE_INVENTORY
                    ? (string) __('Magento Inventory is enabled, but %1, which places the reservation, is not installed.', self::HANDLER_PACKAGE)
                    : (string) __('No stock registration handler for mode "%1" is installed.', $mode),
            ];
        }
        if ($websiteId !== null && $mode === Obligation::MODE_INVENTORY) {
            $code = (string) $this->websiteRepository->getById($websiteId)->getCode();
            if ($this->websiteStock->idForWebsiteCode($code) === null) {
                return [
                    'code' => self::PROBLEM_WEBSITE_WITHOUT_STOCK,
                    'problem' => (string) __('Website "%1" is assigned to no stock.', $code),
                ];
            }
        }
        return null;
    }

    public function isReady(?int $websiteId = null): bool
    {
        return $this->problem($websiteId) === null;
    }
}
