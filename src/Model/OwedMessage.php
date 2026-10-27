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
        private readonly ObligationResource $obligations
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
        if ($summary['refused'] > 0) {
            return (string) __(
                'The stock registration of %1 order(s) created through the API was given up after repeated failures, and %2 still owe it. '
                . 'Read bin/magento inventory:order-reservation:list for the reasons; inventory:order-reservation:run <order id> tries one again, '
                . 'and a compensation through inventory:reservation:create-compensations settles it.',
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
