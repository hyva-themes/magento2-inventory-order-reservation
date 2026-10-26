<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * The live store the tests run against: one dedicated sku, read at the
 * start and put back after every test (its reservations deleted, its
 * source and stock-item quantities restored), orders placed the way the
 * REST order endpoint places them.
 */
abstract class StoreCase extends TestCase
{
    public const SKU = 'bed-walnut-cane';
    public const WEBSITE_CODE = 'base';

    protected static ObjectManagerInterface $objectManager;

    /** @var array<string, float> source code => quantity before the tests */
    private static array $sourceBaseline = [];
    private static ?float $legacyBaseline = null;

    /**
     * Called by the bootstrap with the booted object manager.
     */
    public static function boot(ObjectManagerInterface $objectManager): void
    {
        self::$objectManager = $objectManager;
    }

    protected function setUp(): void
    {
        $this->readBaseline();
        $this->restoreSku();
    }

    protected function tearDown(): void
    {
        $this->restoreSku();
    }

    // ---- the store

    protected function inventoryInstalled(): bool
    {
        return self::$objectManager->get(OptionalInventory::class)->isInstalled();
    }

    protected function requireInventory(): void
    {
        if (!$this->inventoryInstalled()) {
            self::markTestSkipped('Magento Inventory is not installed on this store');
        }
    }

    protected function requireLegacy(): void
    {
        if ($this->inventoryInstalled()) {
            self::markTestSkipped('this store runs Magento Inventory; the legacy case needs a store without it');
        }
    }

    protected function connection(): AdapterInterface
    {
        return self::$objectManager->get(ResourceConnection::class)->getConnection();
    }

    protected function table(string $name): string
    {
        return self::$objectManager->get(ResourceConnection::class)->getTableName($name);
    }

    /**
     * The stock the website sells from.
     */
    protected function websiteStockId(): int
    {
        $connection = $this->connection();
        $select = $connection->select()->from($this->table('inventory_stock_sales_channel'), ['stock_id'])
            ->where('type = ?', 'website')->where('code = ?', self::WEBSITE_CODE);
        return (int) $connection->fetchOne($select);
    }

    /**
     * A stock the website does not sell from.
     */
    protected function otherStockId(): int
    {
        $connection = $this->connection();
        $select = $connection->select()->from($this->table('inventory_stock'), ['stock_id'])
            ->where('stock_id <> ?', $this->websiteStockId())->order('stock_id')->limit(1);
        $id = (int) $connection->fetchOne($select);
        if ($id === 0) {
            self::markTestSkipped('the store has one stock only; the wrong-stock case needs a second');
        }
        return $id;
    }

    /**
     * The sources linked to the website's stock, highest priority first.
     * @return string[]
     */
    protected function websiteSources(): array
    {
        $connection = $this->connection();
        $select = $connection->select()->from($this->table('inventory_source_stock_link'), ['source_code'])
            ->where('stock_id = ?', $this->websiteStockId())->order('priority');
        return array_map('strval', $connection->fetchCol($select));
    }

    // ---- orders

    /**
     * An order for the sku, placed through the repository as the REST
     * endpoint places it: guest, checkmo, one simple line, the flag as asked.
     */
    protected function placeOrder(float $qty = 1.0, bool $reserveStock = true, string $sku = self::SKU): OrderInterface
    {
        $productId = $this->productId($sku);
        $price = 10.0;
        /** @var Order $order */
        $order = self::$objectManager->create(Order::class);
        $order->setStoreId(1)
            ->setState(Order::STATE_NEW)
            ->setStatus('pending')
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(0)
            ->setCustomerEmail('inventory-order-reservation@example.com')
            ->setCustomerFirstname('Integration')
            ->setCustomerLastname('Test')
            ->setBaseCurrencyCode('EUR')->setOrderCurrencyCode('EUR')->setGlobalCurrencyCode('EUR')->setStoreCurrencyCode('EUR')
            ->setSubtotal($price * $qty)->setBaseSubtotal($price * $qty)
            ->setGrandTotal($price * $qty)->setBaseGrandTotal($price * $qty)
            ->setTotalQtyOrdered($qty)
            ->setShippingMethod('flatrate_flatrate')
            ->setShippingDescription('Flat Rate');
        /** @var Order\Item $item */
        $item = self::$objectManager->create(Order\Item::class);
        $item->setProductId($productId)->setProductType('simple')->setSku($sku)->setName($sku)
            ->setQtyOrdered($qty)->setPrice($price)->setBasePrice($price)
            ->setRowTotal($price * $qty)->setBaseRowTotal($price * $qty)->setIsVirtual(0)->setStoreId(1);
        $order->addItem($item);
        foreach (['billing', 'shipping'] as $type) {
            /** @var Order\Address $address */
            $address = self::$objectManager->create(Order\Address::class);
            $address->setAddressType($type)->setFirstname('Integration')->setLastname('Test')
                ->setStreet('Teststraat 1')->setCity('Heerlen')->setPostcode('6411AA')->setCountryId('NL')
                ->setTelephone('0000000000')->setEmail('inventory-order-reservation@example.com');
            $type === 'billing' ? $order->setBillingAddress($address) : $order->setShippingAddress($address);
        }
        /** @var Order\Payment $payment */
        $payment = self::$objectManager->create(Order\Payment::class);
        $payment->setMethod('checkmo');
        $order->setPayment($payment);
        if ($reserveStock) {
            $extension = $order->getExtensionAttributes() ?? self::$objectManager->get(OrderExtensionFactory::class)->create();
            $extension->setReserveStock(true);
            $order->setExtensionAttributes($extension);
        }
        return self::$objectManager->get(OrderRepositoryInterface::class)->save($order);
    }

    protected function order(int $orderId): OrderInterface
    {
        return self::$objectManager->get(OrderRepositoryInterface::class)->get($orderId);
    }

    protected function orderState(int $orderId): string
    {
        $connection = $this->connection();
        return (string) $connection->fetchOne(
            $connection->select()->from($this->table('sales_order'), ['state'])->where('entity_id = ?', $orderId)
        );
    }

    protected function ship(int $orderId, ?string $sourceCode = null): int
    {
        $arguments = self::$objectManager->create(\Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface::class);
        if ($sourceCode !== null) {
            // extension attributes come from their generated factory, as the REST layer builds them
            $extension = self::$objectManager->get(\Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionFactory::class)->create();
            $extension->setSourceCode($sourceCode);
            $arguments->setExtensionAttributes($extension);
        }
        return (int) self::$objectManager->get(ShipOrderInterface::class)
            ->execute($orderId, [], false, false, null, [], [], $arguments);
    }

    protected function cancel(int $orderId): void
    {
        self::assertTrue(self::$objectManager->get(OrderManagementInterface::class)->cancel($orderId), 'the order cancels');
    }

    protected function invoice(int $orderId): int
    {
        return (int) self::$objectManager->get(InvoiceOrderInterface::class)->execute($orderId, true);
    }

    /**
     * A full credit memo of the order's one line, returned to stock.
     */
    protected function refundReturningToStock(int $orderId): int
    {
        $order = $this->order($orderId);
        $items = [];
        $itemIds = [];
        foreach ($order->getItems() as $orderItem) {
            $creation = self::$objectManager->create(\Magento\Sales\Api\Data\CreditmemoItemCreationInterface::class);
            $creation->setOrderItemId((int) $orderItem->getItemId())->setQty((float) $orderItem->getQtyOrdered());
            $items[] = $creation;
            $itemIds[] = (int) $orderItem->getItemId();
        }
        $arguments = self::$objectManager->create(\Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface::class);
        $extension = self::$objectManager->get(\Magento\Sales\Api\Data\CreditmemoCreationArgumentsExtensionFactory::class)->create();
        $extension->setReturnToStockItems($itemIds);
        $arguments->setExtensionAttributes($extension);
        return (int) self::$objectManager->get(RefundOrderInterface::class)
            ->execute($orderId, $items, false, false, null, $arguments);
    }

    // ---- the module

    protected function obligations(int $orderId): array
    {
        return self::$objectManager->get(ObligationResource::class)->forOrder($orderId);
    }

    protected function obligationStatus(int $orderId, string $sku = self::SKU): string
    {
        foreach ($this->obligations($orderId) as $obligation) {
            if ($obligation->sku === $sku) {
                return $obligation->status;
            }
        }
        return 'none';
    }

    /**
     * An owed obligation written by hand: an order whose registration never
     * ran, the state a failed registration leaves.
     */
    protected function insertOwed(int $orderId, float $qty, string $sku = self::SKU): void
    {
        $inventory = $this->inventoryInstalled();
        self::$objectManager->get(ObligationResource::class)->insert([
            new Obligation(
                $orderId,
                $sku,
                'simple',
                $qty,
                1,
                $inventory ? $this->websiteStockId() : null,
                $inventory ? Obligation::MODE_INVENTORY : Obligation::MODE_LEGACY
            ),
        ]);
    }

    protected function register(int $orderId): bool
    {
        return self::$objectManager->get(StockRegistrationInterface::class)->register($orderId);
    }

    // ---- stock

    /**
     * The reservation rows that name the order, oldest first.
     * @return array<int, array<string, mixed>>
     */
    protected function reservationRows(int $orderId, string $sku = self::SKU): array
    {
        $connection = $this->connection();
        $order = $this->order($orderId);
        $select = $connection->select()->from($this->table('inventory_reservation'))
            ->where('sku = ?', $sku)
            ->where(
                'metadata LIKE ' . $connection->quote('%"object_id":"' . $orderId . '"%')
                . ' OR metadata LIKE ' . $connection->quote('%"object_id":' . $orderId . ',%')
                . ' OR metadata LIKE ' . $connection->quote('%"object_id":' . $orderId . '}%')
                . ' OR metadata LIKE ' . $connection->quote('%"object_increment_id":"' . $order->getIncrementId() . '"%')
            )
            ->order('reservation_id');
        return $connection->fetchAll($select);
    }

    protected function reservationSum(int $orderId, int $stockId, string $sku = self::SKU): float
    {
        $sum = 0.0;
        foreach ($this->reservationRows($orderId, $sku) as $row) {
            if ((int) $row['stock_id'] === $stockId) {
                $sum += (float) $row['quantity'];
            }
        }
        return round($sum, 4);
    }

    protected function reservationEvents(int $orderId, string $sku = self::SKU): array
    {
        $events = [];
        foreach ($this->reservationRows($orderId, $sku) as $row) {
            $meta = json_decode((string) $row['metadata'], true) ?: [];
            $events[] = ($meta['event_type'] ?? '?') . ':' . (int) $row['stock_id'] . ':' . sprintf('%+.1f', (float) $row['quantity']);
        }
        return $events;
    }

    protected function sourceQty(string $sourceCode, string $sku = self::SKU): float
    {
        $connection = $this->connection();
        return (float) $connection->fetchOne(
            $connection->select()->from($this->table('inventory_source_item'), ['quantity'])
                ->where('sku = ?', $sku)->where('source_code = ?', $sourceCode)
        );
    }

    protected function legacyQty(string $sku = self::SKU): float
    {
        $connection = $this->connection();
        return (float) $connection->fetchOne(
            $connection->select()->from($this->table('cataloginventory_stock_item'), ['qty'])
                ->where('product_id = ?', $this->productId($sku))
        );
    }

    /**
     * A manual compensation row as Magento's command builds it.
     */
    protected function manualCompensation(int $orderId, int $stockId, float $qty, string $sku = self::SKU): object
    {
        $builder = self::$objectManager->get(\Magento\InventoryReservationsApi\Model\ReservationBuilderInterface::class);
        return $builder->setSku($sku)->setStockId($stockId)->setQuantity($qty)
            ->setMetadata(json_encode(['event_type' => 'manual_compensation', 'object_type' => 'order', 'object_id' => $orderId]))
            ->build();
    }

    /**
     * Appends through Inventory's writer, the path Magento's command takes.
     * @param object[] $reservations
     */
    protected function append(array $reservations): void
    {
        self::$objectManager->get(\Magento\InventoryReservationsApi\Model\AppendReservationsInterface::class)->execute($reservations);
    }

    protected function productId(string $sku): int
    {
        $connection = $this->connection();
        $id = (int) $connection->fetchOne(
            $connection->select()->from($this->table('catalog_product_entity'), ['entity_id'])->where('sku = ?', $sku)
        );
        if ($id === 0) {
            self::fail(sprintf('sku %s is not in this store\'s catalog', $sku));
        }
        return $id;
    }

    // ---- the sku's state

    private function readBaseline(): void
    {
        if (self::$sourceBaseline !== [] || self::$legacyBaseline !== null) {
            return;
        }
        $connection = $this->connection();
        if ($this->inventoryInstalled()) {
            $rows = $connection->fetchPairs(
                $connection->select()->from($this->table('inventory_source_item'), ['source_code', 'quantity'])->where('sku = ?', self::SKU)
            );
            foreach ($rows as $code => $qty) {
                self::$sourceBaseline[(string) $code] = (float) $qty;
            }
        }
        self::$legacyBaseline = $this->legacyQty();
    }

    /**
     * The sku as it was: no reservations, the source quantities and the
     * stock item back at their baseline. The orders stay; they are history.
     */
    private function restoreSku(): void
    {
        $connection = $this->connection();
        if ($this->inventoryInstalled()) {
            $connection->delete($this->table('inventory_reservation'), ['sku = ?' => self::SKU]);
            foreach (self::$sourceBaseline as $code => $qty) {
                $connection->update(
                    $this->table('inventory_source_item'),
                    ['quantity' => $qty, 'status' => 1],
                    ['sku = ?' => self::SKU, 'source_code = ?' => $code]
                );
            }
        }
        if (self::$legacyBaseline !== null) {
            $connection->update(
                $this->table('cataloginventory_stock_item'),
                ['qty' => self::$legacyBaseline, 'is_in_stock' => 1],
                ['product_id = ?' => $this->productId(self::SKU)]
            );
        }
    }
}
