<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\Notification\MessageInterface;

/**
 * The admin system message that names orders owed longer than an hour:
 * recovery has not caught up, and the stock the website sells on is
 * wrong by what those orders sold until it does.
 */
class OwedMessage implements MessageInterface
{
    public const OWED_FOR_SECONDS = 3600;

    public function __construct(
        private readonly ObligationResource $obligations,
        private readonly Readiness $readiness,
        private readonly InventoryPresence $inventory
    ) {
    }

    /**
     * The message's identity for the admin notification list.
     *
     */
    public function getIdentity(): string
    {
        return 'hyva_inventory_order_reservation_owed';
    }

    /**
     * Shown while an obligation has been owed longer than an hour.
     *
     */
    public function isDisplayed(): bool
    {
        $summary = $this->obligations->owedSummary();
        if ($summary['refused'] > 0) {
            return true;
        }
        if ($summary['count'] > 0 && $this->readiness->problem() !== null) {
            // an installation problem is named at once, not after an hour
            return true;
        }
        if ($summary['count'] === 0 || $summary['oldest'] === null) {
            return false;
        }
        return strtotime($summary['oldest'] . ' UTC') <= time() - self::OWED_FOR_SECONDS;
    }

    /**
     * The count of owed orders, the oldest, and the commands that settle them.
     *
     */
    public function getText(): string
    {
        $summary = $this->obligations->owedSummary();
        $problem = $this->readiness->problem();
        if ($problem !== null && $summary['count'] > 0) {
            return (string) __(
                'Stock registration cannot run: %1 %2 order(s) created through the API wait for it, and the first registration after the fix completes them.',
                $problem,
                $summary['count']
            );
        }
        if ($summary['refused'] > 0 && $this->inventory->isInventory()) {
            return (string) __(
                'The stock registration of %1 order(s) created through the API was refused, and %2 still owe it. '
                . 'Read bin/magento inventory:order-reservation:list for the reasons; inventory:order-reservation:run <order id> tries one again, '
                . 'a compensation through inventory:reservation:create-compensations settles a refused row on an Inventory stock, '
                . 'and inventory:order-reservation:settle <order id> closes the rows without writing stock, once the stock is corrected by hand.',
                $summary['refused'],
                $summary['count']
            );
        }
        if ($summary['refused'] > 0) {
            // without Inventory there is no compensation command
            return (string) __(
                'The stock registration of %1 order(s) created through the API was refused, and %2 still owe it. '
                . 'Read bin/magento inventory:order-reservation:list for the reasons; inventory:order-reservation:run <order id> tries one again, '
                . 'and inventory:order-reservation:settle <order id> closes it without writing stock, once the stock is corrected by hand.',
                $summary['refused'],
                $summary['count']
            );
        }
        return (string) __(
            '%1 order(s) created through the API still owe their stock registration (oldest since %2). '
            . 'Run bin/magento inventory:order-reservation:run, and read bin/magento inventory:order-reservation:list '
            . 'for the reason.',
            $summary['count'],
            (string) $summary['oldest']
        );
    }

    /**
     * Major: the website sells stock that left the shop until this is settled.
     *
     */
    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
