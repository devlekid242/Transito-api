<?php

namespace App\Command;

use App\Entity\Admin;
use App\Entity\User;
use App\Repository\AdminRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée (ou met à jour) un compte administrateur back-office en ligne de commande.
 *
 * Remplace SuperAdminFixture pour un usage hors recette : peut être lancée
 * en production sans dépendre de doctrine:fixtures:load (qui n'est pas
 * censé tourner sur une base de prod).
 *
 * Exemples :
 *   php bin/console app:admin:create
 *   php bin/console app:admin:create --email=admin@transito.cg --role=SUPER_ADMIN
 *   php bin/console app:admin:create --email=admin@transito.cg --password=Secret123! --no-interaction
 */
#[AsCommand(
    name: 'transito:admin:create',
    description: 'Crée ou met à jour un compte administrateur du back-office',
)]
final class CreateAdminCommand extends Command
{
    private const ROLES = ['SUPER_ADMIN', 'ADMIN', 'MODERATEUR', 'SUPPORT', 'FINANCE'];

    /** Permissions par défaut proposées selon le rôle métier choisi. */
    private const DEFAULT_PERMISSIONS = [
        'SUPER_ADMIN' => [
            'view_users',
            'edit_users',
            'delete_users',
            'suspend_users',
            'view_finance',
            'approve_withdrawals',
            'approve_refunds',
            'view_agencies',
            'edit_agencies',
            'suspend_agencies',
            'verify_kyc',
            'view_support',
            'respond_support',
            'view_reports',
            'export_data',
            'manage_admins',
            'view_settings',
            'edit_settings',
        ],
        'ADMIN' => [
            'view_users',
            'edit_users',
            'suspend_users',
            'view_finance',
            'approve_withdrawals',
            'approve_refunds',
            'view_agencies',
            'edit_agencies',
            'verify_kyc',
            'view_support',
            'respond_support',
            'view_reports',
            'export_data',
        ],
        'FINANCE' => [
            'view_finance',
            'approve_withdrawals',
            'approve_refunds',
            'view_reports',
            'export_data',
        ],
        'MODERATEUR' => [
            'view_agencies',
            'edit_agencies',
            'verify_kyc',
            'view_users',
        ],
        'SUPPORT' => [
            'view_users',
            'view_support',
            'respond_support',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly AdminRepository $adminRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email de connexion de l\'administrateur')
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Numéro de téléphone (ex: +242060000001)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom complet affiché')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe (généré aléatoirement si omis en mode non-interactif)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, sprintf('Rôle admin métier (%s)', implode('|', self::ROLES)), 'SUPER_ADMIN')
            ->addOption('department', null, InputOption::VALUE_REQUIRED, 'Département / service', 'Direction générale')
            ->addOption('permissions', null, InputOption::VALUE_REQUIRED, 'Permissions séparées par des virgules (remplace les permissions par défaut du rôle)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ne pas demander de confirmation si le compte existe déjà')
            ->setHelp(
                <<<'HELP'
Cette commande crée un compte User + Admin, ou met à jour le compte Admin
existant si l'email fourni correspond déjà à un utilisateur.

Elle est prévue pour remplacer les fixtures de type "super admin" dans les
environnements où l'on ne veut pas exécuter doctrine:fixtures:load
(notamment en production).
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Création / mise à jour d\'un compte administrateur');

        // ----- Collecte des informations (options ou prompts interactifs) -----

        $email = $input->getOption('email') ?: $io->ask('Email de connexion', null, function (?string $value) {
            return $this->assertValidEmail($value);
        });
        $email = $this->assertValidEmail($email);

        $name = $input->getOption('name') ?: $io->ask('Nom complet', 'Administrateur Transito');

        $phone = $input->getOption('phone') ?: $io->ask('Téléphone', '+242060000000');

        $role = strtoupper((string) ($input->getOption('role') ?: $io->choice('Rôle admin', self::ROLES, 'SUPER_ADMIN')));
        if (!in_array($role, self::ROLES, true)) {
            $io->error(sprintf('Rôle invalide "%s". Rôles acceptés : %s', $role, implode(', ', self::ROLES)));
            return Command::FAILURE;
        }

        $department = $input->getOption('department') ?: 'Direction générale';

        $permissionsOption = $input->getOption('permissions');
        $permissions = $permissionsOption
            ? array_values(array_filter(array_map('trim', explode(',', $permissionsOption))))
            : self::DEFAULT_PERMISSIONS[$role];

        // ----- Mot de passe -----

        $generatedPassword = null;
        $password = $input->getOption('password');
        if (!$password) {
            if ($input->isInteractive()) {
                $password = $io->askHidden('Mot de passe (min. 8 caractères)');
                $confirm = $io->askHidden('Confirmer le mot de passe');
                if ($password !== $confirm) {
                    $io->error('Les mots de passe ne correspondent pas.');
                    return Command::FAILURE;
                }
            } else {
                // Mode non-interactif (CI, script de déploiement...) : on génère
                // un mot de passe robuste plutôt que d'échouer silencieusement.
                $generatedPassword = $password = bin2hex(random_bytes(9));
            }
        }

        if (strlen($password) < 8) {
            $io->error('Le mot de passe doit contenir au moins 8 caractères.');
            return Command::FAILURE;
        }

        // ----- Création / mise à jour de l'utilisateur -----

        $user = $this->userRepository->findOneBy(['email' => $email]);
        $isNewUser = $user === null;

        if (!$isNewUser && !$input->getOption('force')) {
            $io->warning(sprintf('Un utilisateur existe déjà avec l\'email "%s".', $email));
            if (!$io->confirm('Voulez-vous mettre à jour ce compte et lui attribuer/rafraîchir le rôle admin ?', false)) {
                $io->comment('Opération annulée.');
                return Command::SUCCESS;
            }
        }

        if ($isNewUser) {
            $user = new User();
            $user->setEmail($email);
        }

        $user->setFullName($name);
        $user->setPhoneNumber($phone);
        $user->setStatus('active');
        $user->setPhoneVerified(true);
        $user->setEmailVerified(true);
        // Le rôle métier (SUPER_ADMIN, ADMIN, ...) est porté par Admin::adminRole.
        // User::getRoles() en dérive ROLE_ADMIN / ROLE_SUPER_ADMIN côté sécurité.
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);

        // ----- Création / mise à jour du profil Admin -----

        $admin = $this->adminRepository->findOneBy(['user' => $user]);
        $isNewAdmin = $admin === null;

        if ($isNewAdmin) {
            $admin = new Admin();
            $admin->setUser($user);
        }

        $admin->setAdminRole($role);
        $admin->setStatus('active');
        $admin->setDepartment($department);
        $admin->setPermissions($permissions);
        $admin->setNotes(sprintf(
            'Compte créé/mis à jour via app:admin:create le %s.',
            (new \DateTime())->format('Y-m-d H:i')
        ));

        $this->em->persist($admin);
        $this->em->flush();

        // ----- Récapitulatif -----

        $io->success(sprintf(
            '%s le compte administrateur "%s" (%s).',
            $isNewUser ? 'Créé' : 'Mis à jour',
            $name,
            $email
        ));

        $io->table(
            ['Champ', 'Valeur'],
            [
                ['Email', $email],
                ['Téléphone', $phone],
                ['Rôle', $role],
                ['Département', $department],
                ['Permissions', implode(', ', $permissions)],
            ]
        );

        if ($generatedPassword) {
            $io->warning([
                'Mot de passe généré automatiquement (à communiquer de façon sécurisée) :',
                $generatedPassword,
                'Il ne sera plus jamais affiché : pensez à le faire changer à la première connexion.',
            ]);
        }

        return Command::SUCCESS;
    }

    private function assertValidEmail(?string $email): string
    {
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Email invalide.');
        }

        return $email;
    }
}
