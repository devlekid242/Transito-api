<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Service\DomainStateTransitionService;
use App\Service\NotificationBroadcastService;
use App\Service\WalletService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'transito:trips:sync-lifecycle',
    description: 'Synchronise automatiquement les états des voyages et finalise les passagers absents.'
)]
final class SyncTripLifecycleCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
        private NotificationBroadcastService $broadcaster,
        private DomainStateTransitionService $stateTransitions,
        #[Autowire('%env(int:TRIP_BOARDING_WINDOW_MINUTES)%')]
        private int $boardingWindowMinutes = 60,
        #[Autowire('%env(int:TRIP_NO_SHOW_GRACE_MINUTES)%')]
        private int $noShowGraceMinutes = 30,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $window = max(0, $this->boardingWindowMinutes);
        $grace = max(0, $this->noShowGraceMinutes);

        $trips = $this->em->getRepository(Trip::class)->createQueryBuilder('t')
            ->andWhere('t.status IN (:statuses)')
            ->andWhere('t.departureTime IS NOT NULL')
            ->setParameter('statuses', ['planifie', 'embarquement', 'en_route'])
            ->orderBy('t.departureTime', 'ASC')
            ->getQuery()->getResult();

        $changed = 0;
        $noShows = 0;

        foreach ($trips as $candidate) {
            $connection = $this->em->getConnection();
            $connection->beginTransaction();
            try {
                /** @var Trip|null $trip */
                $trip = $this->em->getRepository(Trip::class)->find($candidate->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$trip || $trip->getStatus() === 'annule') {
                    $connection->rollBack();
                    continue;
                }

                $departure = $trip->getDepartureTime();
                if (!$departure) {
                    $connection->rollBack();
                    continue;
                }
                $departureAt = \DateTimeImmutable::createFromInterface($departure);
                $statusChanged = false;

                // planifie -> embarquement : la fenêtre d'embarquement est ouverte.
                if ($trip->getStatus() === 'planifie'
                    && $now >= $departureAt->modify(sprintf('-%d minutes', $window))
                    && $now < $departureAt) {
                    $trip->setStatus('embarquement');
                    $statusChanged = true;
                }

                // embarquement -> en_route : l'heure de départ est atteinte.
                if (in_array($trip->getStatus(), ['planifie', 'embarquement'], true) && $now >= $departureAt) {
                    $trip->setStatus('en_route');
                    $statusChanged = true;
                }

                // Après la période de grâce, tout billet payé encore en attente devient NO_SHOW.
                if ($now >= $departureAt->modify(sprintf('+%d minutes', $grace)) && $trip->getStatus() === 'en_route') {
                    $tickets = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
                        ->join('t.reservation', 'r')
                        ->andWhere('r.trip = :trip')
                        ->andWhere('r.paymentStatus = :paid')
                        ->andWhere('t.status = :pending')
                        ->setParameter('trip', $trip)
                        ->setParameter('paid', 'paye')
                        ->setParameter('pending', 'en_attente')
                        ->getQuery()->getResult();

                    foreach ($tickets as $ticket) {
                        $lockedTicket = $this->em->getRepository(Ticket::class)->find($ticket->getId(), LockMode::PESSIMISTIC_WRITE);
                        if (!$lockedTicket || $lockedTicket->getStatus() !== 'en_attente') {
                            continue;
                        }

                        $this->stateTransitions->transitionTicket($lockedTicket, 'no_show');
                        $lockedTicket->setQrCodeToken(null);
                        $this->walletService->processTicketNoShow($lockedTicket);
                        $this->em->persist($lockedTicket);
                        $noShows++;

                        $reservation = $lockedTicket->getReservation();
                        if ($reservation) {
                            $allTickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
                            $allNoShow = count($allTickets) > 0 && count(array_filter(
                                $allTickets,
                                static fn(Ticket $t): bool => $t->getStatus() === 'no_show'
                            )) === count($allTickets);
                            if ($allNoShow) {
                                $this->stateTransitions->transitionReservationPayment($reservation, 'no_show');
                                $this->em->persist($reservation);
                            }

                            if ($reservation->getUser()) {
                                $notification = new Notification();
                                $notification->setRecipientType('user')
                                    ->setRecipientId($reservation->getUser()->getId())
                                    ->setTitle('Billet non présenté')
                                    ->setContent(sprintf(
                                        'Votre billet TKT-%d n’a pas été présenté dans le délai d’embarquement du trajet %s → %s.',
                                        $lockedTicket->getId(),
                                        $trip->getDepartureCity() ?? '',
                                        $trip->getArrivalCity() ?? ''
                                    ))
                                    ->setCategory('TRAVEL');
                                $this->em->persist($notification);
                            }
                        }
                    }
                }

                // en_route -> termine uniquement lorsque l'heure d'arrivée estimée est connue et atteinte.
                $arrival = $trip->getEstimatedArrivalTime();
                if ($arrival && $trip->getStatus() === 'en_route' && $now >= \DateTimeImmutable::createFromInterface($arrival)) {
                    $trip->setStatus('termine');
                    $statusChanged = true;
                }

                if ($statusChanged) {
                    $this->em->persist($trip);
                }

                $this->em->flush();
                $connection->commit();

                if ($statusChanged) {
                    $changed++;
                }

                // Les notifications sont créées dans la transaction et diffusées après commit.
                // On récupère les notifications récentes de ce voyage pour éviter un broadcast avant commit.
                // Le canal persistant reste la source de vérité si le broadcaster est indisponible.
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $output->writeln(sprintf('<error>Voyage #%d : %s</error>', $candidate->getId(), $e->getMessage()));
            }
        }

        $output->writeln(sprintf(
            '<info>Cycle voyages synchronisé : %d changement(s), %d no-show.</info>',
            $changed,
            $noShows
        ));

        return Command::SUCCESS;
    }
}
