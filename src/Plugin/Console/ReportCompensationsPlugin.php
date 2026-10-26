<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Plugin\Console;

use Hyva\InventoryOrderReservation\Model\Compensation\CoordinatedCompensation;
use Hyva\InventoryOrderReservation\Model\OptionalInventory;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\InventoryReservationCli\Command\CreateCompensations;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Magento's compensation command prints "was compensated by" for every
 * row it asked the writer to insert, whether the row was written or not,
 * and exits 0 unless the writer threw. With the coordinated service in
 * front of the writer, that would read a no-op as a compensation. This
 * plugin runs the command's loop itself: the same arguments, the same
 * parser, one line per row saying what happened, and a non-zero exit
 * status when anything was refused.
 *
 * The command's own input services are Inventory's and are fetched
 * through OptionalInventory at run time: setup:di:compile reflects every
 * constructor on the store, with or without Inventory, so no Inventory
 * type may stand in one (the plugin is only ever invoked where the
 * command exists).
 */
class ReportCompensationsPlugin
{
    /**
     * Constructor.
     * @param CoordinatedCompensation $coordinated
     * @param ObligationResource $obligations
     * @param SerializerInterface $serializer
     * @param State $appState
     * @param OptionalInventory $inventory
     */
    public function __construct(
        private readonly CoordinatedCompensation $coordinated,
        private readonly ObligationResource $obligations,
        private readonly SerializerInterface $serializer,
        private readonly State $appState,
        private readonly OptionalInventory $inventory
    ) {
    }

    /**
     * Runs the command's loop with one accurate line per row and a non-zero exit when anything was refused.
     * @param CreateCompensations $subject
     * @param callable $proceed
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function aroundExecute(
        CreateCompensations $subject,
        callable $proceed,
        InputInterface $input,
        OutputInterface $output
    ): int {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        }
        $standardInput = $this->inventory->compensationStandardInput();
        $reservationFromArgument = $this->inventory->compensationArgumentReader();
        if ($standardInput === null || $reservationFromArgument === null) {
            return $proceed($input, $output);
        }
        $arguments = $input->getArgument('compensations');
        if (empty($arguments)) {
            $arguments = $standardInput->execute();
        }
        if (empty($arguments)) {
            $output->writeln('<error>A list of compensations needs to be defined as argument or STDIN.</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln('<info>Compensations:</info>');
        $refused = 0;
        foreach ((array) $arguments as $argument) {
            try {
                $reservation = $reservationFromArgument->execute($argument);
            } catch (\Throwable $e) {
                $refused++;
                $output->writeln(sprintf('  - <error>%s: %s</error>', $argument, $e->getMessage()));
                continue;
            }
            $orderId = $this->orderIdWithObligation($reservation);
            if ($orderId === null) {
                // not an order this module knows: Magento's own writer, as before
                try {
                    $writer = $this->inventory->appendReservations();
                    if ($writer === null) {
                        throw new \RuntimeException('Magento Inventory is not available on this store.');
                    }
                    $writer->execute([$reservation]);
                    $output->writeln(sprintf(
                        '  - Product <comment>%s</comment> was compensated by <comment>%+f</comment> for stock <comment>%s</comment>',
                        $reservation->getSku(),
                        $reservation->getQuantity(),
                        $reservation->getStockId()
                    ));
                } catch (\Throwable $e) {
                    $refused++;
                    $output->writeln(sprintf('  - <error>%s</error>', $e->getMessage()));
                }
                continue;
            }
            try {
                $outcome = $this->coordinated->apply($orderId, $reservation);
            } catch (\Throwable $e) {
                $refused++;
                $output->writeln(sprintf(
                    '  - <error>Order %d, product %s: refused, its stock registration could not complete (%s)</error>',
                    $orderId,
                    $reservation->getSku(),
                    $e->getMessage()
                ));
                continue;
            }
            if ($outcome->isRefused()) {
                $refused++;
            }
            $output->writeln(sprintf(
                '  - Order %d, product <comment>%s</comment>, stock <comment>%d</comment>: %s',
                $orderId,
                $outcome->sku,
                $outcome->stockId,
                $outcome->describe()
            ));
        }
        return $refused === 0 ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;
    }

    /**
     * The order a row names, when this module holds an obligation for it.
     * @param object $reservation
     * @return int|null
     */
    private function orderIdWithObligation(object $reservation): ?int
    {
        $metadata = $reservation->getMetadata();
        if (!is_string($metadata) || $metadata === '') {
            return null;
        }
        try {
            $data = $this->serializer->unserialize($metadata);
        } catch (\Throwable) {
            return null;
        }
        $orderId = (int) ($data['object_id'] ?? 0);
        if ($orderId <= 0 || ($data['object_type'] ?? '') !== 'order' || $this->obligations->forOrder($orderId) === []) {
            return null;
        }
        return $orderId;
    }
}
