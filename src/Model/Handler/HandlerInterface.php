<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Handler;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Writes the stock side of a placed order for what the order still owes
 * and answers for each owed row: placed, balanced or refused. Called
 * inside the order's lock and transaction; a failure must be thrown, so
 * the transaction rolls the write back with every row's state.
 */
interface HandlerInterface
{
    /**
     * @param Obligation[] $owed the owed rows, one per sku
     * @return RowOutcome[] one per owed row
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function register(OrderInterface $order, array $owed): array;
}
