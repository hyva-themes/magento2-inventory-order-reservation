<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Console;

use Hyva\InventoryOrderReservation\Console\OrderIdArguments;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * Order entity ids from the command line: positive decimals in the
 * sales_order.entity_id range, all or none.
 */
class OrderIdArgumentsTest extends TestCase
{
    public function test_positive_decimal_ids_are_read_as_ids(): void
    {
        self::assertSame([7, 4294967295], (new OrderIdArguments())->parse(['7', '4294967295']));
    }

    public function test_every_malformed_id_is_named(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not an order entity id: "42abc", "42,43".');

        (new OrderIdArguments())->parse(['7', '42abc', '42,43']);
    }
}
