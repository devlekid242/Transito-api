<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\OtpChallenge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'transito:auth:cleanup-otp',
    description: 'Supprime les challenges OTP expirés ou consommés depuis plus de 24 heures.'
)]
final class CleanupOtpChallengesCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cutoff = new \DateTimeImmutable('-24 hours');
        $count = $this->em->createQueryBuilder()
            ->delete(OtpChallenge::class, 'o')
            ->where('o.requestedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $output->writeln(sprintf('<info>%d challenge(s) OTP supprimé(s).</info>', $count));
        return Command::SUCCESS;
    }
}
