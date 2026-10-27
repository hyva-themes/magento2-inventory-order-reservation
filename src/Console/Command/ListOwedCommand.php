<?php
/**
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Console\Command;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento inventory:order-reservation:list
 */
class ListOwedCommand extends Command
{
    public function __construct(
        private readonly ObligationResource $obligations
    ) {
        parent::__construct();
    }

    /**
     * Names the command and what it lists.
     *
     */
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
        $ids = $this->obligations->orderIdsOwed(gmdate('Y-m-d H:i:s'));
        $refused = $this->obligations->orderIdsRefused();
        if ($ids === [] && $refused === []) {
            $output->writeln('<info>No order owes its stock registration.</info>');
            return Cli::RETURN_SUCCESS;
        }
        foreach ($ids as $orderId) {
            foreach ($this->obligations->forOrder($orderId) as $obligation) {
                if (!$obligation->isOwed()) {
                    continue;
                }
                $output->writeln(sprintf(
                    'order %d  sku %s  qty %s  mode %s  attempts %d%s',
                    $orderId,
                    $obligation->sku,
                    rtrim(rtrim(number_format($obligation->qtyOrdered, 4, '.', ''), '0'), '.'),
                    $obligation->mode,
                    $obligation->attempts,
                    $obligation->lastError !== null ? '  last error: ' . $obligation->lastError : ''
                ));
            }
        }
        foreach ($refused as $orderId) {
            foreach ($this->obligations->forOrder($orderId) as $obligation) {
                if (!$obligation->isRefused()) {
                    continue;
                }
                $output->writeln(sprintf(
                    '<comment>order %d  sku %s  qty %s  mode %s  given up after %d attempts%s  (inventory:order-reservation:run %d tries again)</comment>',
                    $orderId,
                    $obligation->sku,
                    rtrim(rtrim(number_format($obligation->qtyOrdered, 4, '.', ''), '0'), '.'),
                    $obligation->mode,
                    $obligation->attempts,
                    $obligation->lastError !== null ? '  last error: ' . $obligation->lastError : '',
                    $orderId
                ));
            }
        }

        return Cli::RETURN_SUCCESS;
    }
}
