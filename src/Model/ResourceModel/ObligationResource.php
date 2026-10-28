<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\ResourceModel;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The obligation table. Every write happens on the connection the caller
 * holds a transaction on (the order's save, or the order's lock), so a
 * rollback takes the rows with it.
 */
class ObligationResource
{
    public const TABLE = 'hyva_inventory_order_stock_registration';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * The connection the sales tables live on.
     */
    public function connection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection('sales');
    }

    /**
     * Whether the sales connection and the default one are the same
     * adapter: the condition under which one transaction covers the order
     * row, the obligation rows and the inventory writes.
     */
    public function isOneConnection(): bool
    {
        return $this->resourceConnection->getConnection('sales') === $this->resourceConnection->getConnection();
    }

    /**
     * @param Obligation[] $obligations
     */
    public function insert(array $obligations): void
    {
        if ($obligations === []) {
            return;
        }
        $rows = [];
        foreach ($obligations as $obligation) {
            $rows[] = [
                'order_id' => $obligation->orderId,
                'product_id' => $obligation->productId,
                'sku' => $obligation->sku,
                'product_type' => $obligation->productType,
                'qty_ordered' => $obligation->qtyOrdered,
                'website_id' => $obligation->websiteId,
                'stock_id' => $obligation->stockId,
                'mode' => $obligation->mode,
                'status' => $obligation->status,
            ];
        }
        $this->connection()->insertMultiple($this->table(), $rows);
    }

    /**
     * Whether the order has obligation rows on record, any status.
     */
    public function hasRowsFor(int $orderId): bool
    {
        $connection = $this->connection();
        $select = $connection->select()->from($this->table(), ['entity_id'])->where('order_id = ?', $orderId)->limit(1);
        return $connection->fetchOne($select) !== false;
    }

    /**
     * The order's obligations, every status, for reading under the lock.
     */
    public function forOrder(int $orderId, bool $forUpdate = false): array
    {
        $connection = $this->connection();
        $select = $connection->select()->from($this->table())->where('order_id = ?', $orderId)->order('entity_id');
        if ($forUpdate) {
            // read under the lock a caller's outer transaction already holds
            $select->forUpdate(true);
        }
        return array_map($this->fromRow(...), $connection->fetchAll($select));
    }

    /**
     * The order's obligations still owed, in row order.
     *
     * @return Obligation[]
     */
    public function owedForOrder(int $orderId, bool $forUpdate = false): array
    {
        return array_values(array_filter(
            $this->forOrder($orderId, $forUpdate),
            static fn (Obligation $obligation): bool => $obligation->isOwed()
        ));
    }

    /**
     * Ids of orders with an obligation still owed, created before `$before`
     * (a database timestamp), oldest first.
     *
     * @return int[]
     */
    public function orderIdsOwed(string $before, int $limit = 200): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->distinct()
            ->from($this->table(), ['order_id'])
            ->where('status = ?', Obligation::STATUS_OWED)
            ->where('attempts < ?', Obligation::MAX_ATTEMPTS)
            ->where('created_at < ?', $before)
            // the orders tried least first, so a few that keep failing never
            // hold the rest back
            ->order(['attempts', 'created_at'])
            ->limit($limit);
        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * How many orders owe, and the oldest owed creation time; for the
     * admin message.
     * @return array{count: int, oldest: ?string, refused: int}
     */
    public function owedSummary(): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table(), ['count' => 'COUNT(DISTINCT order_id)', 'oldest' => 'MIN(created_at)'])
            ->where('status = ?', Obligation::STATUS_OWED);
        $row = $connection->fetchRow($select) ?: ['count' => 0, 'oldest' => null];
        $refused = $connection->select()
            ->from($this->table(), ['count' => 'COUNT(DISTINCT order_id)'])
            ->where('status = ?', Obligation::STATUS_REFUSED);

        return [
            'count' => (int) $row['count'],
            'oldest' => $row['oldest'] ?: null,
            'refused' => (int) $connection->fetchOne($refused),
        ];
    }

    /**
     * One sku of the order settled, the others untouched.
     */
    public function markRowDone(int $orderId, string $sku, string $settledBy): void
    {
        $this->settle(['order_id = ?' => $orderId, 'sku = ?' => $sku], $settledBy);
    }

    /**
     * Every owed or refused row of the order closed by hand, in the
     * caller's transaction: no stock is written.
     *
     * @return int the rows closed
     */
    public function markOrderSettledByHand(int $orderId): int
    {
        return $this->settle(['order_id = ?' => $orderId], Obligation::SETTLED_BY_HAND);
    }

    /**
     * One sku of the order refused for a reason a placement cannot fix, in
     * the caller's transaction: a compensation repairs it, and `run <id>`
     * tries it again. No attempt is counted; the reason is the last error.
     */
    public function markRowRefused(int $orderId, string $sku, string $reason): void
    {
        $this->connection()->update(
            $this->table(),
            ['status' => Obligation::STATUS_REFUSED, 'last_error' => mb_substr($reason, 0, 2000)],
            ['order_id = ?' => $orderId, 'sku = ?' => $sku, 'status = ?' => Obligation::STATUS_OWED]
        );
    }

    /**
     * The rows matched, owed or refused, marked done in the caller's transaction.
     *
     * @param array<string, mixed> $where
     * @return int the rows marked
     */
    private function settle(array $where, string $settledBy): int
    {
        return $this->connection()->update(
            $this->table(),
            [
                'status' => Obligation::STATUS_DONE,
                'settled_by' => $settledBy,
                'completed_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
                'last_error' => null,
            ],
            $where + ['status IN (?)' => [Obligation::STATUS_OWED, Obligation::STATUS_REFUSED]]
        );
    }

    /**
     * Records a failed attempt, outside the rolled-back transaction, so the
     * admin message can name the reason.
     */
    public function recordFailure(int $orderId, string $error): int
    {
        $connection = $this->connection();
        $connection->update(
            $this->table(),
            [
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'last_error' => mb_substr($error, 0, 2000),
            ],
            ['order_id = ?' => $orderId, 'status = ?' => Obligation::STATUS_OWED]
        );
        $select = $connection->select()
            ->from($this->table(), ['attempts' => 'MAX(attempts)'])
            ->where('order_id = ?', $orderId)
            ->where('status = ?', Obligation::STATUS_OWED);

        return (int) $connection->fetchOne($select);
    }

    /**
     * The registration gives the order up: its rows leave the owed set
     * (Magento's inconsistency listing shows the order again, a
     * compensation can settle it) and keep the last reason.
     */
    public function markRefused(int $orderId): void
    {
        $this->connection()->update(
            $this->table(),
            ['status' => Obligation::STATUS_REFUSED],
            ['order_id = ?' => $orderId, 'status = ?' => Obligation::STATUS_OWED]
        );
    }

    /**
     * A refused order is tried again: owed, with its attempts counted afresh.
     */
    public function reopen(int $orderId): void
    {
        $this->connection()->update(
            $this->table(),
            ['status' => Obligation::STATUS_OWED, 'attempts' => 0],
            ['order_id = ?' => $orderId, 'status = ?' => Obligation::STATUS_REFUSED]
        );
    }

    /**
     * Ids of orders the registration gave up on.
     */
    public function orderIdsRefused(int $limit = 200): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->distinct()
            ->from($this->table(), ['order_id'])
            ->where('status = ?', Obligation::STATUS_REFUSED)
            ->order('created_at')
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * The obligation table's name with the store's prefix.
     */
    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Obligation
    {
        return new Obligation(
            (int) $row['order_id'],
            (string) $row['sku'],
            $row['product_type'] !== null ? (string) $row['product_type'] : null,
            (float) $row['qty_ordered'],
            (int) $row['website_id'],
            $row['stock_id'] !== null ? (int) $row['stock_id'] : null,
            (string) $row['mode'],
            (string) $row['status'],
            $row['settled_by'] !== null ? (string) $row['settled_by'] : null,
            (int) $row['attempts'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            isset($row['product_id']) && $row['product_id'] !== null ? (int) $row['product_id'] : null
        );
    }
}
