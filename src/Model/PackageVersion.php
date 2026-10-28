<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Composer\InstalledVersions;

/**
 * This package's version as Composer installed it: the tag the store's
 * composer.lock resolved ("1.0.2"), or the branch of a development install
 * ("dev-main"). Read at runtime from Composer's installed.php, so no copy
 * of the version has to be kept in step with the tags. "unknown" when the
 * module was not installed through Composer (a copy in app/code).
 */
class PackageVersion
{
    public const PACKAGE = 'hyva-themes/magento2-inventory-order-reservation';
    public const UNKNOWN = 'unknown';

    public function get(): string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return self::UNKNOWN;
        }
        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? self::UNKNOWN;
    }
}
