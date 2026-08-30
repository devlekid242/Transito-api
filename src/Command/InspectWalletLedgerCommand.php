<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Commande de DIAGNOSTIC UNIQUEMENT (aucune écriture).
 *
 * Affiche, pour un wallet donné, chaque WalletTransaction dans son ordre
 * d'insertion réel (id ASC, qui reflète l'ordre réel d'exécution/commit,
 * contrairement à createdAt qui n'a qu'une précision à la seconde), avec :
 *  - le montant et le type/source déclarés sur la ligne,
 *  - les snapshots available/blocked/reserved AVANT et APRÈS,
 *  - le delta réellement observé sur chaque poche.
 *
 * But : identifier précisément à quelle transaction une valeur a divergé de
 * ce qu'elle aurait dû être, avant de toucher au code applicatif.
 *
 * Usage :
 *   php bin/console transito:finance:inspect-ledger 1
 *   php bin/console transito:finance:inspect-ledger 2
 */
#[AsCommand(
    name: 'transito:finance:inspect-ledger',
    description: 'Diagnostic en lecture seule : affiche le ledger complet d\'un wallet avec deltas.'
)]
final class InspectWalletLedgerCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('walletId', InputArgument::REQUIRED, 'ID du wallet à inspecter (voir "Wallet #X" dans le rapport de réconciliation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $walletId = (int) $input->getArgument('walletId');
        $wallet = $this->em->getRepository(Wallet::class)->find($walletId);

        if (!$wallet) {
            $output->writeln(sprintf('<error>Wallet #%d introuvable.</error>', $walletId));
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Wallet #%d — type: %s — agence: %s</info>',
            $wallet->getId(),
            $wallet->getType(),
            $wallet->getAgency()?->getId() ?? 'N/A (plateforme)'
        ));
        $output->writeln(sprintf(
            'Soldes ACTUELS -> disponible: %s | bloqué: %s | réservé: %s',
            $wallet->getAvailableBalance(),
            $wallet->getBlockedBalance(),
            $wallet->getReservedBalance()
        ));
        $output->writeln('');

        /** @var WalletTransaction[] $rows */
        $rows = $this->em->getRepository(WalletTransaction::class)->createQueryBuilder('wt')
            ->andWhere('wt.wallet = :wallet')
            ->setParameter('wallet', $wallet)
            ->orderBy('wt.createdAt', 'ASC')
            ->addOrderBy('wt.id', 'ASC')
            ->getQuery()
            ->getResult();

        $table = new Table($output);
        $table->setHeaders([
            'ID', 'Créé le', 'Type', 'Source', 'Montant', 'Résa/Retrait',
            'Δ dispo', 'Δ bloqué', 'Δ réservé',
            'Dispo après', 'Bloqué après', 'Réservé après',
        ]);

        $prev = null;
        foreach ($rows as $row) {
            $availAfter = $row->getAvailableAfter();
            $blockedAfter = $row->getBlockedAfter();
            $reservedAfter = $row->getReservedAfter();

            $deltaAvail = ($prev && $availAfter !== null) ? bcsub($availAfter, $prev['available'], 2) : 'N/A';
            $deltaBlocked = ($prev && $blockedAfter !== null) ? bcsub($blockedAfter, $prev['blocked'], 2) : 'N/A';
            $deltaReserved = ($prev && $reservedAfter !== null) ? bcsub($reservedAfter, $prev['reserved'], 2) : 'N/A';

            $ref = $row->getReservation()
                ? 'résa#' . $row->getReservation()->getId()
                : ($row->getWithdrawalRequest() ? 'retrait#' . $row->getWithdrawalRequest()->getId() : '-');

            $table->addRow([
                $row->getId(),
                $row->getCreatedAt()?->format('Y-m-d H:i:s'),
                $row->getType(),
                $row->getSource(),
                $row->getAmount(),
                $ref,
                $deltaAvail,
                $deltaBlocked,
                $deltaReserved,
                $availAfter ?? 'N/A (legacy)',
                $blockedAfter ?? 'N/A (legacy)',
                $reservedAfter ?? 'N/A (legacy)',
            ]);

            if ($availAfter !== null && $blockedAfter !== null && $reservedAfter !== null) {
                $prev = ['available' => $availAfter, 'blocked' => $blockedAfter, 'reserved' => $reservedAfter];
            }
        }

        $table->render();

        $output->writeln('');
        $output->writeln('<comment>Astuce : compare la colonne "Montant" avec les colonnes Δ pour chaque ligne.</comment>');
        $output->writeln('<comment>Un Δ qui ne correspond pas au Montant/Source indique où l\'incohérence a été introduite.</comment>');

        return Command::SUCCESS;
    }
}
