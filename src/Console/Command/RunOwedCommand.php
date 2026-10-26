<?php
/**
 * Copyright © Hyvä Themes B.V. All rights reserved.
 * See LICENSE.md for the terms.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Console\Command;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento inventory:order-reservation:run [order-id ...]
 */
class RunOwedCommand extends Command
{
    /**
     * Constructor.
     * @param ObligationResource $obligations
     * @param StockRegistrationInterface $registration
     * @param State $appState
     */
    public function __construct(
        private readonly ObligationResource $obligations,
        private readonly StockRegistrationInterface $registration,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * Names the command and its optional order ids.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('inventory:order-reservation:run')
            ->setDescription('Registers the stock owed by orders created through the API: every owed order, or the ones named')
            ->addArgument('order-id', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Order entity ids');
    }

    /**
     * Registers every owed order, or the ones named; exits non-zero when one failed.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        }
        $ids = array_map('intval', (array) $input->getArgument('order-id'));
        if ($ids === []) {
            $ids = $this->obligations->orderIdsOwed(gmdate('Y-m-d H:i:s'));
        }
        $failed = 0;
        foreach ($ids as $orderId) {
            try {
                $ran = $this->registration->register($orderId);
                $output->writeln(sprintf('order %d: %s', $orderId, $ran ? 'registered' : 'nothing owed'));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf('<error>order %d: %s</error>', $orderId, $e->getMessage()));
            }
        }
        return $failed === 0 ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;
    }
}
