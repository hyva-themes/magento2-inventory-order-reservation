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
 * Composer's runtime record of the installed packages, the vendor
 * directory's installed.php, behind an object so its readers can be
 * tested without the vendor directory's own data deciding the result.
 */
class InstalledPackages
{
    /**
     * The version Composer installed the package at ("1.0.2", "dev-main"), or null when it is not installed.
     */
    public function prettyVersion(string $package): ?string
    {
        return InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
    }
}
