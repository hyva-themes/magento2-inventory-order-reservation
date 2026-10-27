<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\InventoryPresence;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\CouldNotSaveException;
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

    /** A configurable in the Koti sample data and the child the composite cases sell. */
    protected const CONFIGURABLE_SKU = 'bedding-linen';
    protected const CHILD_SKU = 'bedding-linen-white';
    /**
     * An owed obligation no handler can take: every registration attempt
     * the order's saves make fails and leaves it owed, which is the state
     * a store in trouble leaves while the order is shipped, canceled or
     * refunded. `releaseRegistration` gives it its mode back.
     */
    protected const MODE_HELD = 'held';
    /** The skus the base class puts back after every test. */
    private const RESTORED_SKUS = [self::SKU, self::CHILD_SKU];
    /** @var array<string, array<string, float>> sku => source code => quantity before the tests */
    private static array $sourceBaseline = [];
    /** @var array<string, float> sku => stock item quantity before the tests */
    private static array $legacyBaseline = [];

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
        return self::$objectManager->get(InventoryPresence::class)->isInventory();
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
        return $this->placeOrderWithLines([$sku => $qty], $reserveStock);
    }

    /**
     * @param array<string, float> $qtyBySku one simple line per sku
     */
    protected function placeOrderWithLines(array $qtyBySku, bool $reserveStock = true): OrderInterface
    {
        $price = 10.0;
        $totalQty = array_sum($qtyBySku);
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
            ->setSubtotal($price * $totalQty)->setBaseSubtotal($price * $totalQty)
            ->setGrandTotal($price * $totalQty)->setBaseGrandTotal($price * $totalQty)
            ->setTotalQtyOrdered($totalQty)
            ->setShippingMethod('flatrate_flatrate')
            ->setShippingDescription('Flat Rate');
        foreach ($qtyBySku as $sku => $qty) {
            $item = self::$objectManager->create(Order\Item::class);
            $item->setProductId($this->productId($sku))->setProductType('simple')->setSku($sku)->setName($sku)
                ->setQtyOrdered($qty)->setPrice($price)->setBasePrice($price)
                ->setRowTotal($price * $qty)->setBaseRowTotal($price * $qty)->setIsVirtual(0)->setStoreId(1);
            $order->addItem($item);
        }
        foreach (['billing', 'shipping'] as $type) {
            $address = self::$objectManager->create(Order\Address::class);
            $address->setAddressType($type)->setFirstname('Integration')->setLastname('Test')
                ->setStreet('Teststraat 1')->setCity('Heerlen')->setPostcode('6411AA')->setCountryId('NL')
                ->setTelephone('0000000000')->setEmail('inventory-order-reservation@example.com');
            $type === 'billing' ? $order->setBillingAddress($address) : $order->setShippingAddress($address);
        }
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

    protected function placeConfigurableOrder(float $qty = 1.0, bool $reserveStock = true): OrderInterface
    {
        $price = 20.0;
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
        /** @var Order\Item $parent */
        $parent = self::$objectManager->create(Order\Item::class);
        $parent->setProductId($this->productId(self::CONFIGURABLE_SKU))->setProductType('configurable')
            ->setSku(self::CHILD_SKU)->setName(self::CONFIGURABLE_SKU)
            ->setQtyOrdered($qty)->setPrice($price)->setBasePrice($price)
            ->setRowTotal($price * $qty)->setBaseRowTotal($price * $qty)->setIsVirtual(0)->setStoreId(1)
            ->setProductOptions(['simple_sku' => self::CHILD_SKU, 'simple_name' => self::CHILD_SKU]);
        /** @var Order\Item $child */
        $child = self::$objectManager->create(Order\Item::class);
        $child->setProductId($this->productId(self::CHILD_SKU))->setProductType('simple')
            ->setSku(self::CHILD_SKU)->setName(self::CHILD_SKU)
            ->setQtyOrdered($qty)->setPrice(0.0)->setBasePrice(0.0)
            ->setRowTotal(0.0)->setBaseRowTotal(0.0)->setIsVirtual(0)->setStoreId(1)
            ->setParentItem($parent);
        $order->addItem($parent);
        $order->addItem($child);
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

    /**
     * The order's parent line of a configurable: the line a shipment names.
     */
    protected function parentItemId(int $orderId): int
    {
        $connection = $this->connection();
        return (int) $connection->fetchOne(
            $connection->select()->from($this->table('sales_order_item'), ['item_id'])
                ->where('order_id = ?', $orderId)->where('parent_item_id IS NULL')->where('product_type = ?', 'configurable')
        );
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

    /**
     * @param array<int, float> $items order item id => quantity; empty ships everything
     */
    protected function ship(int $orderId, ?string $sourceCode = null, array $items = []): int
    {
        $lines = [];
        foreach ($items as $itemId => $itemQty) {
            /** @var \Magento\Sales\Api\Data\ShipmentItemCreationInterface $line */
            $line = self::$objectManager->create(\Magento\Sales\Api\Data\ShipmentItemCreationInterface::class);
            $line->setOrderItemId((int) $itemId)->setQty((float) $itemQty);
            $lines[] = $line;
        }
        $arguments = self::$objectManager->create(\Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface::class);
        if ($sourceCode !== null) {
            // extension attributes come from their generated factory, as the REST layer builds them
            $extension = self::$objectManager->get(\Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionFactory::class)->create();
            $extension->setSourceCode($sourceCode);
            $arguments->setExtensionAttributes($extension);
        }
        return (int) self::$objectManager->get(ShipOrderInterface::class)
            ->execute($orderId, $lines, false, false, null, [], [], $arguments);
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
    protected function insertOwed(int $orderId, float $qty, string $sku = self::SKU, ?string $mode = null): void
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
                $mode ?? $this->storeMode()
            ),
        ]);
    }

    /**
     * The mode this store registers in.
     */
    protected function storeMode(): string
    {
        return $this->inventoryInstalled() ? Obligation::MODE_INVENTORY : Obligation::MODE_LEGACY;
    }

    protected function insertHeld(int $orderId, float $qty, string $sku = self::SKU): void
    {
        $this->insertOwed($orderId, $qty, $sku, self::MODE_HELD);
    }

    protected function releaseRegistration(int $orderId): void
    {
        $this->connection()->update(
            $this->table(ObligationResource::TABLE),
            ['mode' => $this->storeMode()],
            ['order_id = ?' => $orderId, 'mode = ?' => self::MODE_HELD]
        );
    }

    /**
     * How often registration was attempted for the order's sku.
     */
    protected function obligationAttempts(int $orderId, string $sku = self::SKU): int
    {
        $connection = $this->connection();
        return (int) $connection->fetchOne(
            $connection->select()->from($this->table(ObligationResource::TABLE), ['attempts'])
                ->where('order_id = ?', $orderId)->where('sku = ?', $sku)
        );
    }

    protected function register(int $orderId): bool
    {
        return self::$objectManager->get(StockRegistrationInterface::class)->register($orderId);
    }

    protected function refuse(int $orderId): void
    {
        self::$objectManager->get(ObligationResource::class)->markRefused($orderId);
    }

    /** @return int[] */
    protected function orderIdsRefused(): array
    {
        return array_map('intval', self::$objectManager->get(ObligationResource::class)->orderIdsRefused());
    }

    /**
     * Runs a bin/magento command as its own process, as an operator does:
     * the command sets its own area code and reads its own standard input.
     *
     * @param string[] $arguments
     * @return array{exit: int, out: string}
     */
    protected function runCommand(string $command, array $arguments = [], ?string $stdin = null): array
    {
        $root = self::magentoRoot();
        $argv = [PHP_BINARY, $root . '/bin/magento', $command, ...$arguments];
        $pipes = [];
        // the command is the thing under test, as an operator runs it; an argv array, no shell
        // phpcs:ignore Magento2.Security.InsecureFunction.Found
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // phpcs:ignore Magento2.Security.InsecureFunction.Found
        $exit = proc_close($process);
        return ['exit' => $exit, 'out' => $out . $err];
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
        return $this->reservationRow($orderId, $stockId, $qty, $sku, 'manual_compensation');
    }

    /**
     * A reservation row naming the order under the given event type; a
     * type other than manual_compensation passes the module's plugin by.
     */
    protected function reservationRow(int $orderId, int $stockId, float $qty, string $sku, string $eventType): object
    {
        $builder = self::$objectManager->get(\Magento\InventoryReservationsApi\Model\ReservationBuilderInterface::class);
        return $builder->setSku($sku)->setStockId($stockId)->setQuantity($qty)
            ->setMetadata(json_encode(['event_type' => $eventType, 'object_type' => 'order', 'object_id' => $orderId]))
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

    /**
     * The Magento root the tests run against, inside the store's container.
     */
    protected static function magentoRoot(): string
    {
        return getenv('MAGENTO_ROOT') ?: '/var/www/html';
    }

    /**
     * A manual compensation for a sku that owes nothing: refused as balanced, nothing written.
     */
    protected function appendExpectingBalanced(int $orderId, int $stockId, float $qty, string $sku = self::SKU): void
    {
        try {
            $this->append([$this->manualCompensation($orderId, $stockId, $qty, $sku)]);
            self::fail('an already balanced sku must refuse the request');
        } catch (CouldNotSaveException $e) {
            self::assertStringContainsString('already balanced, nothing written', $e->getMessage());
        }
    }

    /**
     * The sku's reservation rows gone: the inconsistency Magento's listing finds.
     */
    protected function dropPlacements(string $sku = self::SKU): void
    {
        $this->connection()->delete($this->table('inventory_reservation'), ['sku = ?' => $sku]);
    }

    /**
     * A second process holding the order's lock while registering it, for the given seconds: a real lock holder.
     *
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    protected function holdLockWhileRegistering(int $orderId, int $seconds): array
    {
        $root = self::magentoRoot();
        $marker = sys_get_temp_dir() . '/ior-lock-' . $orderId . '-' . uniqid();
        $command = sprintf(
            '%s %s %s %d %d %s register',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/bin/hold-order-lock.php'),
            escapeshellarg($root),
            $orderId,
            $seconds,
            escapeshellarg($marker)
        );
        $pipes = [];
        // a second PHP process is the point of this test: a real lock holder
        // phpcs:ignore Magento2.Security.InsecureFunction.Found
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $deadline = microtime(true) + 60;
        while (!file_exists($marker)) {
            if (microtime(true) > $deadline) {
                self::fail('the lock holder never took the lock: ' . stream_get_contents($pipes[2]));
            }
            usleep(100000);
        }
        return [$process, $pipes, $marker];
    }

    /**
     * @param array{0: resource, 1: array<int, resource>, 2: string} $child
     */
    protected function finish(array $child): void
    {
        [$process, $pipes, $marker] = $child;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        // phpcs:ignore Magento2.Security.InsecureFunction.Found
        $exit = proc_close($process);
        if (is_file($marker)) {
            unlink($marker);
        }
        self::assertSame(0, $exit, 'the lock holder ended cleanly: ' . $err);
        self::assertStringContainsString('released', $out);
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
        if (self::$legacyBaseline !== []) {
            return;
        }
        $connection = $this->connection();
        foreach (self::RESTORED_SKUS as $sku) {
            if ($this->inventoryInstalled()) {
                $rows = $connection->fetchPairs(
                    $connection->select()->from($this->table('inventory_source_item'), ['source_code', 'quantity'])->where('sku = ?', $sku)
                );
                self::$sourceBaseline[$sku] = [];
                foreach ($rows as $code => $qty) {
                    self::$sourceBaseline[$sku][(string) $code] = (float) $qty;
                }
            }
            self::$legacyBaseline[$sku] = $this->legacyQty($sku);
        }
    }

    /**
     * The sku as it was: no reservations, the source quantities and the
     * stock item back at their baseline. The orders stay; they are history.
     */
    private function restoreSku(): void
    {
        $connection = $this->connection();
        foreach (self::RESTORED_SKUS as $sku) {
            if ($this->inventoryInstalled()) {
                $connection->delete($this->table('inventory_reservation'), ['sku = ?' => $sku]);
                foreach (self::$sourceBaseline[$sku] ?? [] as $code => $qty) {
                    $connection->update(
                        $this->table('inventory_source_item'),
                        ['quantity' => $qty, 'status' => 1],
                        ['sku = ?' => $sku, 'source_code = ?' => $code]
                    );
                }
            }
            if (isset(self::$legacyBaseline[$sku])) {
                $productId = $this->productId($sku);
                $connection->update(
                    $this->table('cataloginventory_stock_item'),
                    ['qty' => self::$legacyBaseline[$sku], 'is_in_stock' => 1],
                    ['product_id = ?' => $productId]
                );
                // Magento's stock registry keeps the stock item it last read for
                // the process; a return to stock through it would add to that
                // stale copy and write it back over the row restored here.
                $storage = self::$objectManager->get(\Magento\CatalogInventory\Model\StockRegistryStorage::class);
                $storage->removeStockItem($productId);
                $storage->removeStockStatus($productId);
            }
        }
    }
}
