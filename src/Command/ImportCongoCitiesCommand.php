<?php

namespace App\Command;

use App\Entity\City;
use App\Repository\CityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Insère (ou met à jour) toutes les villes de la République du Congo en base.
 *
 * La liste couvre les 12 chefs-lieux de départements ainsi que les villes de
 * plus de 8 000 habitants (recensement CNSEE 2007), soit les localités
 * réellement gérées comme "villes" dans l'administration territoriale
 * congolaise. Un même import peut être relancé sans risque : les villes déjà
 * présentes (comparaison par nom) sont simplement ignorées, sauf si --update
 * est utilisé.
 *
 * Exemples :
 *   php bin/console transito:city:import
 *   php bin/console transito:city:import --update
 *   php bin/console transito:city:import --dry-run
 */
#[AsCommand(
    name: 'transito:city:import',
    description: 'Importe toutes les villes de la République du Congo dans la base de données',
)]
final class ImportCongoCitiesCommand extends Command
{
    /**
     * name       : nom de la ville
     * code       : code court (laissé à null quand non vérifié, à compléter au besoin)
     * department : département (à titre indicatif, non stocké si l'entité City n'a pas ce champ)
     */
    private const CITIES = [
        ['name' => 'Brazzaville', 'code' => 'BZV', 'department' => 'Brazzaville'],
        ['name' => 'Pointe-Noire', 'code' => 'PNR', 'department' => 'Pointe-Noire'],
        ['name' => 'Dolisie', 'code' => null, 'department' => 'Niari'],
        ['name' => 'Nkayi', 'code' => null, 'department' => 'Bouenza'],
        ['name' => 'Kindamba', 'code' => null, 'department' => 'Pool'],
        ['name' => 'Impfondo', 'code' => null, 'department' => 'Likouala'],
        ['name' => 'Ouesso', 'code' => null, 'department' => 'Sangha'],
        ['name' => 'Madingou', 'code' => null, 'department' => 'Bouenza'],
        ['name' => 'Owando', 'code' => null, 'department' => 'Cuvette'],
        ['name' => 'Sibiti', 'code' => null, 'department' => 'Lékoumou'],
        ['name' => 'Loutété', 'code' => null, 'department' => 'Bouenza'],
        ['name' => 'Bouansa', 'code' => null, 'department' => 'Bouenza'],
        ['name' => 'Gamboma', 'code' => null, 'department' => 'Plateaux'],
        ['name' => 'Mossaka', 'code' => null, 'department' => 'Cuvette'],
        ['name' => 'Mindouli', 'code' => null, 'department' => 'Pool'],
        ['name' => 'Oyo', 'code' => null, 'department' => 'Cuvette'],
        ['name' => 'Makoua', 'code' => null, 'department' => 'Cuvette'],
        ['name' => 'Loudima', 'code' => null, 'department' => 'Bouenza'],
        ['name' => 'Mossendjo', 'code' => null, 'department' => 'Niari'],
        ['name' => 'Mouyondzi', 'code' => null, 'department' => 'Bouenza'],
        ['name' => 'Bétou', 'code' => null, 'department' => 'Likouala'],
        ['name' => 'Djambala', 'code' => null, 'department' => 'Plateaux'],
        ['name' => 'Pokola', 'code' => null, 'department' => 'Sangha'],
        ['name' => 'Ngo', 'code' => null, 'department' => 'Plateaux'],
        ['name' => 'Makabana', 'code' => null, 'department' => 'Niari'],
        ['name' => 'Kinkala', 'code' => null, 'department' => 'Pool'],
        ['name' => 'Ewo', 'code' => null, 'department' => 'Cuvette-Ouest'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CityRepository $cityRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Met à jour le code/pays des villes déjà existantes au lieu de les ignorer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule l\'import sans écrire en base')
            ->setHelp(
                <<<'HELP'
                Cette commande insère en base toutes les villes de la République du Congo
                (liste interne, cf. la constante CITIES de la commande).

                Elle est idempotente : si une ville existe déjà (comparaison par nom), elle
                est ignorée par défaut, ou mise à jour si --update est fourni.
                HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Import des villes de la République du Congo');

        $update = (bool) $input->getOption('update');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Mode simulation (--dry-run) : aucune écriture ne sera faite en base.');
        }

        $total = count(self::CITIES);
        $io->text(sprintf('%d villes à traiter.', $total));
        $io->newLine();

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(
            " %current%/%max% [%bar%] %percent:3s%%\n".
            " Ville en cours : %message%"
        );
        $progressBar->setMessage('—');
        $progressBar->start();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $batchSize = 20;
        $i = 0;

        foreach (self::CITIES as $row) {
            $progressBar->setMessage($row['name']);

            $city = $this->cityRepository->findOneBy(['name' => $row['name']]);

            if ($city === null) {
                $city = new City();
                $city->setName($row['name']);
                $city->setCode($row['code']);
                $city->setCountry('Congo');
                $city->setIsActive(1);

                if (!$dryRun) {
                    $this->em->persist($city);
                }

                ++$created;
            } elseif ($update) {
                $city->setCode($row['code'] ?? $city->getCode());
                $city->setCountry('Congo');

                ++$updated;
            } else {
                ++$skipped;
            }

            ++$i;

            // On flush par lots pour éviter de garder tout l'UnitOfWork en mémoire
            // et pour que la progression reflète un vrai travail d'écriture.
            if (!$dryRun && $i % $batchSize === 0) {
                $this->em->flush();
                $this->em->clear(City::class);
            }

            $progressBar->advance();
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Import terminé : %d créée(s), %d mise(s) à jour, %d ignorée(s) sur %d.',
            $created,
            $updated,
            $skipped,
            $total
        ));

        $io->table(
            ['Résultat', 'Nombre'],
            [
                ['Créées', $created],
                ['Mises à jour', $updated],
                ['Ignorées (déjà existantes)', $skipped],
                ['Total traité', $total],
            ]
        );

        if ($dryRun) {
            $io->comment('Rappel : mode --dry-run, aucune donnée n\'a été écrite en base.');
        }

        return Command::SUCCESS;
    }
}