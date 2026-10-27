<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Integration;

/**
 * Two processes on one order: the second waits on the order's lock and
 * finds the first's work committed, so nothing is written twice.
 */
class ConcurrencyTest extends StoreCase
{
    public function test_a_compensation_waits_for_a_running_registration_and_then_writes_nothing(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);

        $child = $this->holdLockWhileRegistering($orderId, 4);
        $started = microtime(true);
        // the registration balanced the order while the compensation waited: refused as balanced
        $this->appendExpectingBalanced($orderId, $stock, -1.0);
        $waited = microtime(true) - $started;
        $this->finish($child);

        self::assertGreaterThan(2.0, $waited, 'the compensation waited on the lock the registration held');
        self::assertSame(['order_placed:' . $stock . ':-1.0'], $this->reservationEvents($orderId), 'one row, the registration\'s');
        self::assertSame('done', $this->obligationStatus($orderId));
    }

    public function test_two_registrations_write_once(): void
    {
        $inventory = $this->inventoryInstalled();
        $legacyBefore = $this->legacyQty();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);

        $child = $this->holdLockWhileRegistering($orderId, 3);
        $second = $this->register($orderId);
        $this->finish($child);

        self::assertFalse($second, 'the second runner found the first\'s work committed');
        if ($inventory) {
            self::assertSame(['order_placed:' . $this->websiteStockId() . ':-1.0'], $this->reservationEvents($orderId));
        } else {
            self::assertSame($legacyBefore - 1.0, $this->legacyQty());
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    private function holdLockWhileRegistering(int $orderId, int $seconds): array
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
    private function finish(array $child): void
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
}
