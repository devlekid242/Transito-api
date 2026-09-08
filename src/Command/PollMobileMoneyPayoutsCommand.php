<?php

namespace App\Command;

use App\Entity\JobRun;
use App\Entity\PayoutTransaction;
use App\Service\JobReportRecorder;
use App\Service\PayoutService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Équivalent de PollMobileMoneyPaymentsCommand mais pour les décaissements
 * (remboursements clients / retraits partenaires). Contrairement aux
 * paiements, un PayoutTransaction FAILED n'est jamais "rejoué"
 * automatiquement : l'argent a déjà quitté le wallet interne, donc une
 * défaillance ici doit remonter à un humain (voir logs ERROR) plutôt que
 * d'être masquée par une nouvelle tentative silencieuse.
 *
 * Usage : php bin/console app:momo:poll-payouts [--watch]
 */
#[AsCommand(name: 'transito:momo:poll-payouts', description: 'Finalise le statut des remboursements/retraits mobile money en attente')]
class PollMobileMoneyPayoutsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PayoutService $payoutService,
        private readonly JobReportRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('watch', null, null, 'Boucle en continu (Ctrl+C pour arrêter)')
            ->addOption('interval', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Secondes entre deux passes', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $watch = (bool) $input->getOption('watch');
        $interval = max(1, (int) $input->getOption('interval'));

        $checked = 0;
        $issues = [];

        do {
            $pending = $this->em->getRepository(PayoutTransaction::class)->findBy(['status' => PayoutTransaction::STATUS_PENDING]);

            if (!$pending) {
                $output->writeln('<comment>Aucun décaissement PENDING.</comment>');
            }

            foreach ($pending as $payout) {
                /** @var PayoutTransaction $payout */
                $checked++;

                // NOTE : ce try/catch est nouveau par rapport à la version précédente, qui
                // laissait une exception de refreshStatus() interrompre tout le lot restant.
                // Un décaissement qui échoue à se rafraîchir est désormais isolé et remonté
                // comme anomalie, le reste du lot continue d'être traité.
                try {
                    $this->payoutService->refreshStatus($payout);
                } catch (\Throwable $e) {
                    $issues[] = [
                        'code' => 'PAYOUT_REFRESH_FAILED',
                        'reference' => $payout->getReference(),
                        'message' => $e->getMessage(),
                    ];
                    $output->writeln(sprintf('<error>[%s] échec de rafraîchissement: %s</error>', $payout->getReference(), $e->getMessage()));
                    continue;
                }

                $output->writeln(sprintf(
                    '[%s] %s %s -> %s',
                    $payout->getReference(),
                    $payout->getPurpose(),
                    $payout->getAmount(),
                    $payout->getStatus()
                ));
            }

            if ($watch) {
                sleep($interval);
            }
        } while ($watch);

        $this->recorder->finish(
            empty($issues) ? JobRun::STATUS_OK : JobRun::STATUS_WARNING,
            summary: ['checked' => $checked, 'errorCount' => count($issues)],
            issues: $issues,
        );

        return Command::SUCCESS;
    }
}
