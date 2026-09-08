<?php

namespace App\Command;

use App\Entity\JobRun;
use App\Entity\PaymentLog;
use App\Service\JobReportRecorder;
use App\Service\MobileMoney\MobileMoneyException;
use App\Service\MobileMoney\MobileMoneyGatewayFactory;
use App\Service\MobileMoney\MobileMoneyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * En sandbox MTN/Airtel, AUCUN téléphone réel ne valide le paiement, donc
 * l'opérateur ne peut jamais livrer de callback à votre webhook — c'est un
 * comportement documenté, pas un bug de votre intégration (voir le guide
 * joint). La seule façon fiable de connaître le résultat d'un
 * requesttopay/push est donc d'interroger GET .../status en boucle.
 *
 * Cette commande reproduit exactement ce que ferait un vrai callback : elle
 * construit le même payload que PaymentController::webhook() attend et le
 * lui soumet en sous-requête interne, signé avec le même secret HMAC — zéro
 * duplication de la logique métier (verrous, wallet, notifications...).
 *
 * Usage :
 *   php bin/console app:momo:poll-payments            # une passe
 *   php bin/console app:momo:poll-payments --watch     # boucle toutes les 5s (pratique en dev)
 *
 * En production, ce polling reste une bonne roue de secours même une fois
 * les vrais callbacks actifs (callback perdu, réseau instable...) : on peut
 * la garder en cron toutes les minutes sur les PaymentLog PENDING de plus
 * de 30s.
 *
 * Rapport (job_run) : agrégé sur toute la durée du process. En cron (une
 * seule passe), c'est le rapport complet de l'exécution. En --watch (dev,
 * boucle potentiellement infinie tant que Ctrl+C n'est pas envoyé), le
 * rapport n'est écrit qu'à la sortie propre de la boucle — cohérent avec
 * l'usage "outil de dev" documenté ci-dessus, sans changer ce comportement.
 */
#[AsCommand(name: 'transito:momo:poll-payments', description: 'Interroge MTN/Airtel pour les paiements en attente et finalise leur statut')]
class PollMobileMoneyPaymentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MobileMoneyGatewayFactory $gatewayFactory,
        private readonly HttpKernelInterface $httpKernel,
        private readonly string $webhookSecret,
        private readonly JobReportRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('watch', null, null, 'Boucle en continu (Ctrl+C pour arrêter)')
            ->addOption('interval', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Secondes entre deux passes en mode --watch', '5')
            ->addOption('max-age', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Ignorer les PaymentLog PENDING plus vieux que N minutes', '120');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $watch = (bool) $input->getOption('watch');
        $interval = max(1, (int) $input->getOption('interval'));

        $totals = ['checked' => 0, 'forwarded' => 0, 'stillPending' => 0];
        $issues = [];

        do {
            $pass = $this->pollOnce($output, (int) $input->getOption('max-age'));
            $totals['checked'] += $pass['checked'];
            $totals['forwarded'] += $pass['forwarded'];
            $totals['stillPending'] += $pass['stillPending'];
            $issues = array_merge($issues, $pass['issues']);

            if ($watch) {
                sleep($interval);
            }
        } while ($watch);

        $this->recorder->finish(
            empty($issues) ? JobRun::STATUS_OK : JobRun::STATUS_WARNING,
            summary: $totals + ['gatewayErrors' => count($issues)],
            issues: $issues,
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{checked: int, forwarded: int, stillPending: int, issues: array<int, array<string, mixed>>}
     */
    private function pollOnce(OutputInterface $output, int $maxAgeMinutes): array
    {
        $threshold = new \DateTime(sprintf('-%d minutes', $maxAgeMinutes));

        $pending = $this->em->getRepository(PaymentLog::class)->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.createdAt >= :threshold')
            ->setParameter('status', 'PENDING')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();

        $result = ['checked' => count($pending), 'forwarded' => 0, 'stillPending' => 0, 'issues' => []];

        if (!$pending) {
            // var_dump($threshold);
            $output->writeln('<comment>Aucun paiement PENDING à vérifier.</comment>');
            return $result;
        }

        foreach ($pending as $log) {
            /** @var PaymentLog $log */
            try {
                $gateway = $this->gatewayFactory->get($log->getOperator());
                $status = $gateway->getCollectionStatus($log->getReference());
            } catch (MobileMoneyException $e) {
                $result['issues'][] = [
                    'code' => 'MOMO_STATUS_CHECK_FAILED',
                    'reference' => $log->getReference(),
                    'message' => $e->getMessage(),
                ];
                $output->writeln(sprintf('<error>[%s] échec de vérification: %s</error>', $log->getReference(), $e->getMessage()));
                continue;
            }

            if (!$status->isFinal()) {
                $result['stillPending']++;
                $output->writeln(sprintf('[%s] toujours PENDING côté opérateur.', $log->getReference()));
                continue;
            }

            $this->forwardToWebhook($log, $status, $output);
            $result['forwarded']++;
        }

        return $result;
    }

    private function forwardToWebhook(PaymentLog $log, MobileMoneyStatus $result, OutputInterface $output): void
    {
        $payload = [
            'reference' => $log->getReference(),
            'providerReference' => $result->providerReference ?? ($log->getReference() . '_provider'),
            'status' => $result->status, // SUCCESS | FAILED
            'amount' => $log->getAmount(),
            'paidAt' => (new \DateTimeImmutable())->format('c'),
        ];
        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $this->webhookSecret);

        $request = Request::create('/api/payments/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TRANSITO_SIGNATURE' => $signature,
            'HTTP_X_TRANSITO_TIMESTAMP' => $timestamp,
        ], $body);

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        $output->writeln(sprintf(
            '[%s] statut opérateur=%s -> webhook HTTP %d: %s',
            $log->getReference(),
            $result->status,
            $response->getStatusCode(),
            $response->getContent()
        ));
    }
}
