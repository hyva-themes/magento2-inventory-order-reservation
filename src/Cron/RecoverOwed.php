<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Cron;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Psr\Log\LoggerInterface;

/**
 * Every five minutes: the obligations owed longer than two minutes, oldest
 * first. An order whose own invoice or shipment never came to carry the
 * step, or whose registration kept failing, is registered here.
 */
class RecoverOwed
{
    public const OWED_FOR_SECONDS = 120;

    public function __construct(
        private readonly ObligationResource $obligations,
        private readonly StockRegistrationInterface $registration,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Registers the obligations owed longer than two minutes, oldest first; a failure is logged and the obligation stays owed.
     *
     */
    public function execute(): void
    {
        $before = gmdate('Y-m-d H:i:s', time() - self::OWED_FOR_SECONDS);
        foreach ($this->obligations->orderIdsOwed($before) as $orderId) {
            try {
                $this->registration->register($orderId);
            } catch (\Throwable $e) {
                // the registration logged the failure itself
                $this->logger->debug('Stock registration recovery failed for order; it stays owed', [
                    'order_id' => $orderId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
