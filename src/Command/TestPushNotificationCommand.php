<?php

namespace App\Command;

use App\Repository\DeviceTokenRepository;
use App\Repository\UserRepository;
use App\Service\FcmPushService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'transito:test-push',
    aliases: ['app:test-push'],
    description: 'Teste l\'envoi d\'une notification push native (Firebase Cloud Messaging) à un utilisateur ou à un token direct.',
)]
class TestPushNotificationCommand extends Command
{
    public function __construct(
        private FcmPushService $fcmPushService,
        private DeviceTokenRepository $deviceTokenRepository,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::REQUIRED, 'ID utilisateur (ex: 1) ou token FCM direct')
            ->addOption('title', null, InputOption::VALUE_OPTIONAL, 'Titre de la notification', '🔔 Test Transito Push')
            ->addOption('body', null, InputOption::VALUE_OPTIONAL, 'Corps du message', 'Ceci est un test de notification push native en production.')
            ->addOption('category', null, InputOption::VALUE_OPTIONAL, 'Catégorie de notification', 'INFO');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = trim((string)$input->getArgument('target'));
        $title = (string)$input->getOption('title');
        $body = (string)$input->getOption('body');
        $category = (string)$input->getOption('category');

        $io->title('Test d\'envoi de notification push FCM (Transito)');

        $tokens = [];

        if (is_numeric($target)) {
            $userId = (int)$target;
            $user = $this->userRepository->find($userId);
            if (!$user) {
                $io->error(sprintf('Utilisateur avec l\'ID %d introuvable.', $userId));
                return Command::FAILURE;
            }

            $userName = $user->getFullName() ?: $user->getPhoneNumber() ?: ('Utilisateur #' . $userId);
            $io->info(sprintf('Ciblage utilisateur : %s (#%d)', $userName, $userId));

            $deviceTokens = $this->deviceTokenRepository->findBy(['user' => $user]);
            if (empty($deviceTokens)) {
                $io->warning(sprintf('Aucun token d\'appareil enregistré pour l\'utilisateur #%d. Connectez-vous d\'abord sur l\'application mobile avec ce compte.', $userId));
                return Command::FAILURE;
            }

            $io->text(sprintf('%d appareil(s) trouvé(s) pour cet utilisateur :', count($deviceTokens)));
            foreach ($deviceTokens as $dt) {
                $tokenVal = $dt->getToken() ?? '';
                $preview = strlen($tokenVal) > 25
                    ? substr($tokenVal, 0, 12) . '...' . substr($tokenVal, -10)
                    : $tokenVal;

                $io->listing([
                    sprintf(
                        'Plateforme : %s | Token : %s | MAJ : %s',
                        $dt->getPlatform(),
                        $preview,
                        $dt->getUpdatedAt()?->format('Y-m-d H:i:s') ?? 'N/A'
                    ),
                ]);
                if (!empty($tokenVal)) {
                    $tokens[] = $tokenVal;
                }
            }
        } else {
            $io->info('Ciblage par token FCM direct.');
            $tokens[] = $target;
        }

        if (empty($tokens)) {
            $io->error('Aucun token FCM valide à cibler.');
            return Command::FAILURE;
        }

        $io->section('Envoi du message FCM...');
        $io->definitionList(
            ['Titre' => $title],
            ['Corps' => $body],
            ['Catégorie' => $category],
            ['Nombre de destinataires' => count($tokens)],
        );

        $result = $this->fcmPushService->sendToTokens(
            $tokens,
            $title,
            $body,
            [
                'notificationId' => (string)time(),
                'category' => $category,
                'payload' => json_encode(['test' => true, 'timestamp' => time()]),
            ]
        );

        if (!empty($result['error'])) {
            $io->error(sprintf('Échec critique de l\'envoi FCM : %s', $result['error']));
            return Command::FAILURE;
        }

        $success = $result['success'] ?? 0;
        $failure = $result['failure'] ?? 0;
        $stale = $result['stale'] ?? 0;

        if ($success > 0) {
            $io->success(sprintf(
                'Envoi réussi ! Succès : %d | Échecs : %d | Tokens périmés nettoyés : %d',
                $success,
                $failure,
                $stale
            ));
            return Command::SUCCESS;
        }

        $io->error(sprintf(
            'L\'envoi a échoué pour tous les tokens (%d échec(s)).',
            $failure
        ));
        $io->note('Vérifiez que le fichier Transito-api/config/firebase-service-account.json correspond exactement au projet Firebase transito-6808c utilisé par l\'application mobile.');

        return Command::FAILURE;
    }
}

