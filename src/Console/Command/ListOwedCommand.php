<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Console\Command;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\Readiness;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento inventory:order-reservation:list
 */
class ListOwedCommand extends Command
{
    public function __construct(
        private readonly ObligationResource $obligations,
        private readonly Readiness $readiness
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('inventory:order-reservation:list')
            ->setDescription('Lists the orders created through the API that still owe their stock registration, with the last reason');
    }

    /**
     * One line per owed sku: order, sku, quantity, mode, attempts, the last reason.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $problem = $this->readiness->problem();
        if ($problem !== null) {
            $output->writeln('<comment>Stock registration cannot run: ' . OutputFormatter::escape($problem) . '</comment>');
        }
        $owedIds = $this->obligations->orderIdsOwed(gmdate('Y-m-d H:i:s'));
        $refusedIds = $this->obligations->orderIdsRefused();
        if ($owedIds === [] && $refusedIds === []) {
            $output->writeln('<info>No order owes its stock registration.</info>');
            return Cli::RETURN_SUCCESS;
        }
        foreach ($owedIds as $orderId) {
            foreach ($this->obligations->owedForOrder($orderId) as $obligation) {
                $output->writeln(sprintf(
                    'order %d  sku %s  qty %s  mode %s  attempts %d%s',
                    $orderId,
                    OutputFormatter::escape($obligation->sku),
                    $this->qty($obligation),
                    $obligation->mode,
                    $obligation->attempts,
                    $this->lastError($obligation)
                ));
            }
        }
        foreach ($refusedIds as $orderId) {
            foreach ($this->obligations->forOrder($orderId) as $obligation) {
                if (!$obligation->isRefused()) {
                    continue;
                }
                $output->writeln(sprintf(
                    '<comment>order %d  sku %s  qty %s  mode %s  refused%s%s  (%s; inventory:order-reservation:run %d tries again)</comment>',
                    $orderId,
                    OutputFormatter::escape($obligation->sku),
                    $this->qty($obligation),
                    $obligation->mode,
                    $obligation->attempts > 0 ? sprintf(' after %d attempts', $obligation->attempts) : '',
                    $this->lastError($obligation),
                    $obligation->mode === Obligation::MODE_INVENTORY
                        ? 'a compensation repairs it'
                        : sprintf('inventory:order-reservation:settle %d closes it once the stock is corrected by hand', $orderId),
                    $orderId
                ));
            }
        }

        if (count($owedIds) >= ObligationResource::BATCH || count($refusedIds) >= ObligationResource::BATCH) {
            $output->writeln(sprintf(
                '<comment>Only the first %d owed and the first %d refused orders are listed.</comment>',
                ObligationResource::BATCH,
                ObligationResource::BATCH
            ));
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * The quantity without trailing zeros: 2, 1.5, 0.25.
     */
    private function qty(Obligation $obligation): string
    {
        return rtrim(rtrim(number_format($obligation->qtyOrdered, 4, '.', ''), '0'), '.');
    }

    /**
     * The last reason, escaped for the console's markup; empty when there is none.
     */
    private function lastError(Obligation $obligation): string
    {
        return $obligation->lastError !== null ? '  last error: ' . OutputFormatter::escape($obligation->lastError) : '';
    }
}
