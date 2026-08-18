<?php

namespace App\Command;

use App\Service\MobileMoney\MobileMoneyException;
use App\Service\MobileMoney\MobileMoneyGatewayFactory;
use App\Service\MobileMoney\Uuid4Generator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Commande de diagnostic — isole complètement l'appel à l'opérateur du
 * reste du flux (réservation, wallet, webhook...) pour voir l'erreur EXACTE
 * renvoyée par MTN/Airtel, sans dépendre des logs PHP par défaut.
 *
 * Usage :
 *   php bin/console transito:momo:debug-request-to-pay MTN_MOMO 242068001122 100
 *   php bin/console transito:momo:debug-request-to-pay AIRTEL_MONEY 242068001122 100
 */
#[AsCommand(name: 'transito:momo:debug-request-to-pay', description: 'Teste un requestToPay isolé et affiche la réponse/erreur complète')]
class DebugRequestToPayCommand extends Command
{
    public function __construct(private readonly MobileMoneyGatewayFactory $gatewayFactory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('operator', InputArgument::REQUIRED, 'MTN_MOMO ou AIRTEL_MONEY')
            ->addArgument('msisdn', InputArgument::REQUIRED, 'Numéro sandbox, ex: 242068001122')
            ->addArgument('amount', InputArgument::OPTIONAL, 'Montant', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operator = strtoupper($input->getArgument('operator'));
        $msisdn = $input->getArgument('msisdn');
        $amount = $input->getArgument('amount');
        $referenceId = Uuid4Generator::generate();

        $output->writeln("Opérateur : $operator");
        $output->writeln("Reference (X-Reference-Id) : $referenceId");
        $output->writeln("MSISDN : $msisdn");
        $output->writeln("Montant : $amount");
        $output->writeln('---');

        try {
            $gateway = $this->gatewayFactory->get($operator);
        } catch (MobileMoneyException $e) {
            $output->writeln('<error>Gateway introuvable : ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        try {
            $output->writeln('1) Appel requestToPay...');
            $gateway->requestToPay(
                referenceId: $referenceId,
                amount: $amount,
                msisdn: $msisdn,
                externalId: 'debug_' . $referenceId,
                payerMessage: 'Test diagnostic',
                payeeNote: 'Debug',
            );
            $output->writeln('<info>   OK — requête acceptée par l\'opérateur (202/200).</info>');
        } catch (MobileMoneyException $e) {
            $output->writeln('<error>   ÉCHEC requestToPay : ' . $e->getMessage() . '</error>');
            if ($previous = $e->getPrevious()) {
                $output->writeln('<error>   Cause racine : ' . $previous->getMessage() . '</error>');
            }
            return Command::FAILURE;
        }

        $output->writeln('2) Vérification immédiate du statut (peut être PENDING, normal)...');
        try {
            $status = $gateway->getCollectionStatus($referenceId);
            $output->writeln(sprintf(
                '<info>   Statut = %s | providerReference = %s | reason = %s</info>',
                $status->status,
                $status->providerReference ?? 'n/a',
                $status->reason ?? 'n/a'
            ));
            $output->writeln('   Réponse brute : ' . $status->rawResponse);
        } catch (MobileMoneyException $e) {
            $output->writeln('<error>   ÉCHEC vérification statut : ' . $e->getMessage() . '</error>');
        }

        return Command::SUCCESS;
    }
}
