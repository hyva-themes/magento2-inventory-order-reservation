<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Handler;

use Hyva\InventoryOrderReservation\Model\Obligation;

/**
 * What a handler did with one owed row: placed (the quantity it wrote),
 * balanced (nothing to write, the rows already say what the order owes),
 * or refused (nothing a placement can fix, with the reason and what
 * remains on each stock it read, for a compensation by hand). The
 * registration writes each row's state from this, in the one transaction
 * with the rows the handler wrote; an order is complete when every row is.
 */
class RowOutcome
{
    public const PLACED = 'placed';
    public const BALANCED = 'balanced';
    public const REFUSED = 'refused';

    /**
     * @param string $kind one of PLACED, BALANCED, REFUSED
     * @param float $placed the quantity written, for a placed row
     * @param string $reason why a refused row cannot be placed
     * @param array<int, float> $remainders what remains per stock id, as the handler read it, for a refused row
     */
    public function __construct(
        public readonly Obligation $row,
        public readonly string $kind,
        public readonly float $placed = 0.0,
        public readonly string $reason = '',
        public readonly array $remainders = []
    ) {
    }

    public function isRefused(): bool
    {
        return $this->kind === self::REFUSED;
    }

    /**
     * What settles the row when it is done: the registration's write, or the rows that already balanced it.
     */
    public function settledBy(): string
    {
        return $this->kind === self::BALANCED ? Obligation::SETTLED_BY_BALANCED : Obligation::SETTLED_BY_REGISTRATION;
    }
}
