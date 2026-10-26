<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Model\Compensation;

/**
 * What became of one manual compensation under the coordinated service.
 */
class Outcome
{
    public const APPLIED = 'applied';
    public const PARTIAL = 'partial';
    public const ALREADY_BALANCED = 'already_balanced';
    public const REFUSED = 'refused';

    /**
     * Constructor.
     * @param string $kind
     * @param string $sku
     * @param int $stockId
     * @param float $requested
     * @param float $remainingBefore
     * @param float $remainingAfter
     * @param bool $registrationRan
     * @param string $reason
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $sku,
        public readonly int $stockId,
        public readonly float $requested,
        public readonly float $remainingBefore,
        public readonly float $remainingAfter,
        public readonly bool $registrationRan,
        public readonly string $reason = ''
    ) {
    }

    /**
     * Whether the compensation was refused (nothing written).
     *
     * @return bool
     */
    public function isRefused(): bool
    {
        return $this->kind === self::REFUSED;
    }

    /**
     * One line for the command's output: what happened and what remains.
     *
     * @return string
     */
    public function describe(): string
    {
        $sign = static fn (float $q): string => sprintf('%+.4f', $q);
        $prefix = $this->registrationRan ? 'registration completed first; ' : '';
        return match ($this->kind) {
            self::APPLIED => sprintf('%scompensated by %s (balanced)', $prefix, $sign($this->requested)),
            self::PARTIAL => sprintf('%scompensated by %s; %s still owed', $prefix, $sign($this->requested), $sign($this->remainingAfter)),
            self::ALREADY_BALANCED => $prefix . 'already balanced, nothing written',
            default => sprintf('%srefused: %s (the order\'s remaining inconsistency is %s)', $prefix, $this->reason, $sign($this->remainingBefore)),
        };
    }
}
