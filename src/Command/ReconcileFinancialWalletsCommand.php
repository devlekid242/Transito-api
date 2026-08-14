<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FinancialReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'transito:finance:reconcile',
    description: 'Contrôle la cohérence des wallets et de leur ledger.'
)]
final class ReconcileFinancialWalletsCommand extends Command
{
    public function __construct(private readonly FinancialReconciliationService $reconciliation)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->reconciliation->reconcile();

        $output->writeln(sprintf(
            '<info>Wallets contrôlés : %d | incohérents : %d | statut : %s</info>',
            $result['checkedWallets'],
            $result['inconsistentWallets'],
            $result['status']
        ));

        foreach ($result['discrepancies'] as $discrepancy) {
            $output->writeln(sprintf(
                '<error>Wallet #%s (agence #%s) : %s</error>',
                $discrepancy['walletId'] ?? '?',
                $discrepancy['agencyId'] ?? 'plateforme',
                implode(' | ', $discrepancy['issues'])
            ));
        }

        return $result['status'] === 'OK' ? Command::SUCCESS : Command::FAILURE;
    }
}
