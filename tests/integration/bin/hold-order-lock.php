<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 *
 * A second process for the concurrency tests: holds the order's mutex
 * for a while, then registers the order's owed stock inside the lock
 * when asked. usage: php hold-order-lock.php <magento-root> <order-id> <hold-seconds> <marker-file> [register]
 */

declare(strict_types=1);

[, $root, $orderId, $hold, $marker] = $argv;
$register = ($argv[5] ?? '') === 'register';
require $root . '/app/bootstrap.php';
$objectManager = \Magento\Framework\App\Bootstrap::create($root, $_SERVER)->getObjectManager();
try {
    $objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException) {
    // already set
}
$mutex = $objectManager->get(\Magento\Sales\Model\OrderMutexInterface::class);
$registration = $objectManager->get(\Hyva\InventoryOrderReservation\Model\StockRegistration::class);
$mutex->execute(
    (int) $orderId,
    static function () use ($hold, $marker, $register, $registration, $orderId): void {
        file_put_contents($marker, 'held');
        sleep((int) $hold);
        if ($register) {
            $registration->registerLocked((int) $orderId);
        }
    },
    []
);
fwrite(STDOUT, "released\n");
