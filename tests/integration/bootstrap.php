<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 *
 * Bootstrap for the integration tests: a live Magento, found through
 * MAGENTO_ROOT (default /var/www/html), booted the way bin/magento is.
 */

declare(strict_types=1);

$root = getenv('MAGENTO_ROOT') ?: '/var/www/html';
if (!is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "tests: set MAGENTO_ROOT to a Magento install\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}
require $root . '/app/bootstrap.php';
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Hyva\\InventoryOrderReservation\\Test\\Integration\\', __DIR__ . '/');
$objectManager = \Magento\Framework\App\Bootstrap::create($root, $_SERVER)->getObjectManager();
try {
    $objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException) {
    // already set
}
\Hyva\InventoryOrderReservation\Test\Integration\StoreCase::boot($objectManager);
