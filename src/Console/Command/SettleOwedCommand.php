<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Console\Command;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\Console\Cli;
use Magento\Sales\Model\OrderMutexInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento inventory:order-reservation:settle <order-id ...>
 *
 * Closes an order's owed and refused rows without writing any stock, for
 * a merchant who corrected the stock by hand. Without Inventory there is
 * no compensation command, so this is the only way to close a row the
 * registration gave up on. Runs under the order's lock, so it never
 * races a registration of the same order.
 */
class SettleOwedCommand extends Command
{
    public function __construct(
        private readonly ObligationResource $obligations,
        private readonly OrderMutexInterface $orderMutex
    ) {
        parent::__construct();
    }

    /**
     * Names the command and its order ids.
     */
    protected function configure(): void
    {
        $this->setName('inventory:order-reservation:settle')
            ->setDescription('Closes the owed and refused stock registration of the orders named, without writing stock (for stock corrected by hand)')
            ->addArgument('order-id', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Order entity ids');
    }

    /**
     * Settles the rows of every order named; exits non-zero when one failed.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = 0;
        foreach (array_map('intval', (array) $input->getArgument('order-id')) as $orderId) {
            try {
                $settled = (int) $this->orderMutex->execute($orderId, $this->obligations->markOrderSettledByHand(...), [$orderId]);
                $output->writeln(sprintf(
                    'order %d: %s',
                    $orderId,
                    $settled > 0 ? sprintf('%d row(s) settled by hand, no stock written', $settled) : 'nothing to settle'
                ));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf('<error>order %d: %s</error>', $orderId, $e->getMessage()));
            }
        }
        return $failed === 0 ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;
    }
}
