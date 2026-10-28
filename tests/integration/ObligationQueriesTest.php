<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * The obligation table's lookups and the settlement by hand as the
 * database runs them: an order is sorted by its most-tried row and listed
 * once, and a settlement by hand closes the owed and refused rows only.
 * The rows are held (no handler), so nothing touches the stock, and every
 * order is settled by hand at the end, so the recovery never picks it up.
 */
class ObligationQueriesTest extends StoreCase
{
    private const OTHER_SKU = 'hyva-test-obligation-queries';

    public function test_the_owed_lookup_sorts_an_order_by_its_most_tried_row(): void
    {
        $twoRows = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertHeld($twoRows, 1.0);
        $this->insertHeld($twoRows, 1.0, self::OTHER_SKU);
        $oneRow = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertHeld($oneRow, 1.0);
        // the first order's least-tried row is below the second's only row, its most-tried above
        $this->setAttempts($twoRows, self::SKU, 0);
        $this->setAttempts($twoRows, self::OTHER_SKU, 5);
        $this->setAttempts($oneRow, self::SKU, 2);

        try {
            $owed = $this->resource()->orderIdsOwed(gmdate('Y-m-d H:i:s', time() + 3600), 100000);
            self::assertSame([$oneRow, $twoRows], array_values(array_intersect($owed, [$twoRows, $oneRow])));
        } finally {
            $this->settleByHand($twoRows, $oneRow);
        }
    }

    public function test_an_order_with_several_refused_rows_is_listed_once(): void
    {
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertHeld($orderId, 1.0);
        $this->insertHeld($orderId, 1.0, self::OTHER_SKU);
        $this->refuseRow($orderId, self::SKU, 'refused for the test');
        $this->refuseRow($orderId, self::OTHER_SKU, 'refused for the test');

        try {
            $refused = $this->resource()->orderIdsRefused(100000);
            self::assertSame([$orderId], array_values(array_intersect($refused, [$orderId])));
        } finally {
            $this->settleByHand($orderId);
        }
    }

    public function test_a_settlement_by_hand_closes_the_owed_and_refused_rows_and_leaves_a_done_row_as_it_was(): void
    {
        $orderId = (int) $this->placeOrderWithLines([self::SKU => 1.0, self::CHILD_SKU => 1.0], reserveStock: false)->getEntityId();
        $this->insertHeld($orderId, 1.0);
        $this->insertHeld($orderId, 1.0, self::CHILD_SKU);
        $this->insertHeld($orderId, 1.0, self::OTHER_SKU);
        $resource = $this->resource();
        $resource->markRowDone($orderId, self::SKU, Obligation::SETTLED_BY_REGISTRATION);
        $this->refuseRow($orderId, self::CHILD_SKU, 'refused for the test');
        $doneBefore = $this->row($orderId, self::SKU);

        self::assertSame(2, $resource->markOrderSettledByHand($orderId), 'the refused and the owed row');

        self::assertSame($doneBefore, $this->row($orderId, self::SKU), 'the done row keeps its settlement and its completion time');
        foreach ([self::CHILD_SKU, self::OTHER_SKU] as $sku) {
            $row = $this->row($orderId, $sku);
            self::assertSame(Obligation::STATUS_DONE, $row['status'], $sku);
            self::assertSame(Obligation::SETTLED_BY_HAND, $row['settled_by'], $sku);
            self::assertNotNull($row['completed_at'], $sku);
            self::assertNull($row['last_error'], $sku);
        }
        self::assertSame(0, $resource->markOrderSettledByHand($orderId), 'nothing left to close');
    }

    private function resource(): ObligationResource
    {
        return self::$objectManager->get(ObligationResource::class);
    }

    private function setAttempts(int $orderId, string $sku, int $attempts): void
    {
        $this->connection()->update(
            $this->table(ObligationResource::TABLE),
            ['attempts' => $attempts],
            ['order_id = ?' => $orderId, 'sku = ?' => $sku]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $orderId, string $sku): array
    {
        $connection = $this->connection();
        return $connection->fetchRow(
            $connection->select()->from($this->table(ObligationResource::TABLE), ['status', 'settled_by', 'completed_at', 'last_error'])
                ->where('order_id = ?', $orderId)->where('sku = ?', $sku)
        );
    }

    private function settleByHand(int ...$orderIds): void
    {
        foreach ($orderIds as $orderId) {
            $this->resource()->markOrderSettledByHand($orderId);
        }
    }
}
