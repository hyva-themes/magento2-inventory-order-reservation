<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Console\Command;

use Hyva\InventoryOrderReservation\Api\StockRegistrationInterface;
use Hyva\InventoryOrderReservation\Console\OrderIdArguments;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento inventory:order-reservation:run [order-id ...]
 */
class RunOwedCommand extends Command
{
    public function __construct(
        private readonly ObligationResource $obligations,
        private readonly StockRegistrationInterface $registration,
        private readonly State $appState,
        private readonly OrderIdArguments $orderIds
    ) {
        parent::__construct();
    }

    /**
     * Names the command and its optional order ids.
     *
     */
    protected function configure(): void
    {
        $this->setName('inventory:order-reservation:run')
            ->setDescription('Registers the stock owed by orders created through the API: every owed order, or the ones named')
            ->addArgument('order-id', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Order entity ids');
    }

    /**
     * Registers every owed order, or the ones named; exits non-zero when one failed.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        }
        try {
            $ids = $this->orderIds->parse((array) $input->getArgument('order-id'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s Nothing was run.</error>', OutputFormatter::escape($e->getMessage())));
            return Cli::RETURN_FAILURE;
        }
        if ($ids === []) {
            $ids = $this->obligations->orderIdsOwed(gmdate('Y-m-d H:i:s'));
        } else {
            // an order named by hand is tried again, given up on or not
            foreach ($ids as $orderId) {
                $this->obligations->reopen($orderId);
            }
        }
        $failed = 0;
        foreach ($ids as $orderId) {
            try {
                $ran = $this->registration->register($orderId);
                $refused = array_filter($this->obligations->forOrder($orderId), static fn (Obligation $row): bool => $row->isRefused());
                if ($refused !== []) {
                    // a row nothing a placement can fix: the reason names the compensation that repairs it
                    $failed++;
                    foreach ($refused as $row) {
                        $output->writeln(sprintf(
                            '<error>order %d: sku %s refused: %s</error>',
                            $orderId,
                            OutputFormatter::escape($row->sku),
                            OutputFormatter::escape((string) $row->lastError)
                        ));
                    }
                    continue;
                }
                $output->writeln(sprintf('order %d: %s', $orderId, $ran ? 'registered' : 'nothing owed'));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf('<error>order %d: %s</error>', $orderId, $e->getMessage()));
            }
        }
        return $failed === 0 ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;
    }
}
