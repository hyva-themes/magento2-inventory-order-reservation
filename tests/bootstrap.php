<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Bootstrap for the module's standalone unit tests: Magento's vendor
 * autoloader for the framework interfaces the mocks reference, found
 * through MAGENTO_VENDOR_PATH or a sibling Magento install, plus this
 * checkout's own namespaces.
 */

declare(strict_types=1);

$moduleRoot = __DIR__ . '/..';
$candidates = [
    getenv('MAGENTO_VENDOR_PATH') ?: null,
    realpath($moduleRoot . '/../../vendor/autoload.php'),
    realpath($moduleRoot . '/vendor/autoload.php'),
];
$autoload = null;
foreach ($candidates as $candidate) {
    if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "tests: set MAGENTO_VENDOR_PATH to a Magento vendor/autoload.php\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}
$loader = require $autoload;
$loader->addPsr4('Hyva\\InventoryOrderReservation\\', $moduleRoot . '/src/', true);
$loader->addPsr4('Hyva\\InventoryOrderReservation\\Test\\Unit\\', $moduleRoot . '/tests/unit/', true);
