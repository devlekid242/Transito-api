<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\JobRun;
use App\Service\FinancialWorkflowAuditService;
use App\Service\JobReportRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'transito:finance:audit-workflows',
    description: 'Audite la chaîne réservation -> paiement -> ledger -> billet -> embarquement/no-show.'
)]
final class AuditFinancialWorkflowsCommand extends Command
{
    public function __construct(
        private readonly FinancialWorkflowAuditService $audit,
        private readonly JobReportRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->audit->audit();
        $output->writeln(sprintf(
            '<info>Réservations : %d | billets : %d | paiements : %d | anomalies : %d | statut : %s</info>',
            $result['checkedReservations'],
            $result['checkedTickets'],
            $result['checkedPayments'],
            $result['issueCount'],
            $result['status']
        ));

        foreach ($result['issues'] as $issue) {
            $output->writeln(sprintf(
                '<error>[%s] réservation #%s : %s</error>',
                $issue['code'],
                $issue['reservationId'] ?? '?',
                $issue['message']
            ));
        }

        $isOk = $result['status'] === 'OK';

        // Une anomalie d'audit n'est pas un crash technique : on la remonte en WARNING plutôt
        // qu'en ERROR, qui reste réservé aux exceptions non interceptées (gérées automatiquement
        // par JobRunTrackingSubscriber si jamais audit() lève une exception).
        $this->recorder->finish(
            $isOk ? JobRun::STATUS_OK : JobRun::STATUS_WARNING,
            summary: [
                'checkedReservations' => $result['checkedReservations'],
                'checkedTickets' => $result['checkedTickets'],
                'checkedPayments' => $result['checkedPayments'],
                'issueCount' => $result['issueCount'],
            ],
            issues: $result['issues'],
        );

        return $isOk ? Command::SUCCESS : Command::FAILURE;
    }
}
