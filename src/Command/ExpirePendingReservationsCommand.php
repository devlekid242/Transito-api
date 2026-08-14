<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Service\NotificationBroadcastService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'transito:bookings:expire-pending',
    description: 'Expire les réservations impayées et libère leurs sièges après le délai de paiement.'
)]
final class ExpirePendingReservationsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationBroadcastService $broadcaster,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTime();
        $reservations = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->andWhere('r.paymentStatus = :pending')
            ->andWhere('r.paymentExpiresAt IS NOT NULL')
            ->andWhere('r.paymentExpiresAt <= :now')
            ->setParameter('pending', 'en_attente')
            ->setParameter('now', $now)
            ->orderBy('r.paymentExpiresAt', 'ASC')
            ->getQuery()->getResult();

        $count = 0;
        foreach ($reservations as $candidate) {
            $connection = $this->em->getConnection();
            $connection->beginTransaction();
            try {
                /** @var Reservation|null $reservation */
                $reservation = $this->em->getRepository(Reservation::class)->find(
                    $candidate->getId(),
                    LockMode::PESSIMISTIC_WRITE
                );
                if (!$reservation || !$reservation->isPaymentExpired($now)) {
                    $connection->rollBack();
                    continue;
                }

                $trip = $reservation->getTrip();
                if ($trip) {
                    $trip = $this->em->getRepository(\App\Entity\Trip::class)->find(
                        $trip->getId(),
                        LockMode::PESSIMISTIC_WRITE
                    );
                }

                $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
                $freed = 0;
                foreach ($tickets as $ticket) {
                    if (in_array($ticket->getStatus(), ['en_attente'], true)) {
                        $ticket->setStatus('annule');
                        $ticket->setQrCodeToken(null);
                        $freed++;
                        $this->em->persist($ticket);
                    }
                }

                if ($trip && $freed > 0) {
                    $trip->setSeatsReserved(max(0, $trip->getSeatsReserved() - $freed));
                    $this->em->persist($trip);
                }

                $reservation->setPaymentStatus('echoue');
                $reservation->setPaymentExpiresAt(null);
                $this->em->persist($reservation);

                $notification = null;
                if ($reservation->getUser()) {
                    $notification = new Notification();
                    $notification->setRecipientType('user')
                        ->setRecipientId($reservation->getUser()->getId())
                        ->setTitle('Réservation expirée')
                        ->setContent(sprintf(
                            'Votre réservation #%d a expiré car le paiement n’a pas été confirmé dans le délai imparti. Les places ont été libérées.',
                            $reservation->getId()
                        ))
                        ->setCategory('PAYMENT');
                    $this->em->persist($notification);
                }

                $this->em->flush();
                $connection->commit();

                if ($notification) {
                    $this->broadcaster->broadcast($notification);
                }
                $count++;
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $output->writeln(sprintf(
                    '<error>Réservation #%d : %s</error>',
                    $candidate->getId(),
                    $e->getMessage()
                ));
            }
        }

        $output->writeln(sprintf('<info>%d réservation(s) expirée(s).</info>', $count));
        return Command::SUCCESS;
    }
}
