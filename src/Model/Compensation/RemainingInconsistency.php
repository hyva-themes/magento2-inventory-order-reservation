<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Compensation;

use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * Magento's own inconsistency rule, read live for one order and sku: an
 * order in a non-final state should hold reservations summing to minus
 * (ordered - canceled - refunded - shipped, invoiced for a virtual line);
 * a complete, closed or cancelled order should sum to zero. What remains
 * is the expected sum less the rows on record, the amount a compensation
 * may still add.
 */
class RemainingInconsistency
{
    /**
     * Constructor.
     * @param ResourceConnection $resourceConnection
     * @param OptionalInventory $inventory
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly OptionalInventory $inventory
    ) {
    }

    /** @return array{expected: float, actual: float, remaining: float}
     */
    public function forOrderSku(OrderInterface $order, string $sku, int $stockId): array
    {
        $expected = $this->expectedSum($order, $sku);
        $actual = $this->actualSum((int) $order->getEntityId(), (string) $order->getIncrementId(), $sku, $stockId);
        return ['expected' => $expected, 'actual' => $actual, 'remaining' => round($expected - $actual, 4)];
    }

    /**
     * The reservation sum Magento expects for the sku on this order: zero in a final state, else minus what is ordered and neither cancelled, refunded nor shipped (invoiced for a virtual line), for the product types Inventory manages.
     * @param OrderInterface $order
     * @param string $sku
     * @return float
     */
    public function expectedSum(OrderInterface $order, string $sku): float
    {
        if (in_array((string) $order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED, Order::STATE_CANCELED], true)) {
            return 0.0;
        }
        $managed = $this->inventory->isSourceItemManagementAllowedForProductType();
        $qty = 0.0;
        foreach ($order->getItems() as $item) {
            if ((string) $item->getSku() !== $sku) {
                continue;
            }
            $type = (string) $item->getProductType();
            if ($managed !== null && $type !== '' && !$managed->execute($type)) {
                continue;
            }
            $line = (float) $item->getQtyOrdered() - (float) $item->getQtyCanceled() - (float) $item->getQtyRefunded();
            $line -= (float) ($item->getIsVirtual() ? $item->getQtyInvoiced() : $item->getQtyShipped());
            $qty += $line;
        }
        return -$qty;
    }

    /**
     * The sum of the reservation rows on record for the order and sku on the stock, whichever spelling of the order's id the writer used.
     * @param int $orderId
     * @param string $incrementId
     * @param string $sku
     * @param int $stockId
     * @return float
     */
    private function actualSum(int $orderId, string $incrementId, string $sku, int $stockId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_reservation');
        // the three spellings the writers use: a quoted id, a bare id, the increment id
        $select = $connection->select()
            ->from($table, ['total' => 'COALESCE(SUM(quantity), 0)'])
            ->where('sku = ?', $sku)
            ->where('stock_id = ?', $stockId)
            ->where(
                'metadata LIKE ' . $connection->quote('%"object_id":"' . $orderId . '"%')
                . ' OR metadata LIKE ' . $connection->quote('%"object_id":' . $orderId . ',%')
                . ' OR metadata LIKE ' . $connection->quote('%"object_id":' . $orderId . '}%')
                . ' OR metadata LIKE ' . $connection->quote('%"object_increment_id":"' . $incrementId . '"%')
            );
        return (float) $connection->fetchOne($select);
    }
}
