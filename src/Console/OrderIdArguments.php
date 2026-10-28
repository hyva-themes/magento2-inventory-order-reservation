<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Console;

/**
 * Order entity ids typed on the command line, checked as a whole before a
 * command acts on any of them: "42abc", "42,43" or "POS-42" are refused,
 * not read as order 42 the way intval() reads them.
 */
class OrderIdArguments
{
    /** The largest sales_order.entity_id (int unsigned). */
    private const MAX_ID = 4294967295;

    /**
     * @param string[] $arguments
     * @return int[]
     * @throws \InvalidArgumentException naming every argument that is not an order entity id
     */
    public function parse(array $arguments): array
    {
        $invalid = array_filter(
            $arguments,
            static fn ($argument): bool => !is_string($argument)
                || preg_match('/^[1-9][0-9]{0,9}$/', $argument) !== 1
                || (int) $argument > self::MAX_ID
        );
        if ($invalid !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Not an order entity id: %s.',
                implode(', ', array_map(static fn ($argument): string => '"' . $argument . '"', $invalid))
            ));
        }
        return array_map('intval', array_values($arguments));
    }
}
