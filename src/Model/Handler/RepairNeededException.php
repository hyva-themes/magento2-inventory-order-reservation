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
 * A discrepancy the registration cannot place its way out of: a sku
 * reserved past what the order owes. The order is given up on at once,
 * with the reason, so the listing names it and a compensation repairs
 * it, instead of being tried again until the attempt limit.
 */
class RepairNeededException extends LocalizedException
{
}
