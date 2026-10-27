<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Handler;

use Magento\Framework\Exception\LocalizedException;

/**
 * Registration cannot run on this store as installed: the mode's handler
 * package is missing, or an Inventory module is disabled. An order's
 * problem it is not: its obligation stays owed with no attempt counted
 * and no per-order log line, the admin message and the listing name the
 * installation, and the first registration after the fix completes it.
 */
class InstallationProblemException extends LocalizedException
{
}
