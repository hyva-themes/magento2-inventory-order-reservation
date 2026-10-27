<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The stock a website sells from, read with one query from the table
 * Magento's own GetAssignedStockIdForWebsite reads, so that recording an
 * order's obligation inside its save constructs nothing from Inventory.
 * The one Inventory table the core names.
 */
class WebsiteStock
{
    private const TABLE = 'inventory_stock_sales_channel';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * The website's stock id; null when the website is assigned to no stock, or the store has no such table.
     */
    public function idForWebsiteCode(string $websiteCode): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        if (!$connection->isTableExists($table)) {
            return null;
        }
        $select = $connection->select()->from($table, ['stock_id'])
            ->where('type = ?', 'website')
            ->where('code = ?', $websiteCode);
        $id = $connection->fetchOne($select);
        return $id === false || $id === null ? null : (int) $id;
    }
}
