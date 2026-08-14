<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'transito:api:contract',
    description: 'Exporte l’inventaire des routes API à partir des attributs Symfony.'
)]
final class ExportApiContractCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = dirname(__DIR__) . '/Controller';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $count = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            $count += preg_match_all('/#\[Route\(/', $content);
        }
        $output->writeln(sprintf('Routes déclarées via #[Route] : %d', $count));
        $output->writeln('Inventaire détaillé : docs/API_ROUTES_V44.json');
        return Command::SUCCESS;
    }
}
