<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * The catalog's own sku and type for a product id, read in one query on
 * the product entity table: what Magento's placement resolves an order
 * line through (`GetSkusByProductIds`, `GetProductTypesBySkus`), and what
 * the stock side is keyed by. An order line's own sku may carry custom
 * options ("SHIRT-engr") and its type may be the composite parent's.
 */
class ProductIdentities
{
    /** Product types whose stock lives on their children. */
    public const COMPOSITE_TYPES = ['configurable', 'bundle', 'grouped'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<int, array{sku: string, type: string}> by product id; a product no longer in the catalog is absent
     */
    public function byProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id', 'sku', 'type_id'])
            ->where('entity_id IN (?)', $ids);
        $identities = [];
        foreach ($connection->fetchAll($select) as $row) {
            $identities[(int) $row['entity_id']] = ['sku' => (string) $row['sku'], 'type' => (string) $row['type_id']];
        }

        return $identities;
    }

    public static function isComposite(string $type): bool
    {
        return in_array($type, self::COMPOSITE_TYPES, true);
    }
}
