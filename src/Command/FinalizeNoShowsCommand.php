<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Service\AuditLogger;
use App\Service\DomainStateTransitionService;
use App\Service\NotificationBroadcastService;
use App\Service\WalletService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'transito:trips:finalize-no-shows',
    description: 'Compatibilité : finalise les billets non présentés après le départ et la période de grâce.'
)]
final class FinalizeNoShowsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
        private NotificationBroadcastService $broadcaster,
        private DomainStateTransitionService $stateTransitions,
        private AuditLogger $auditLogger,
        #[Autowire('%env(int:TRIP_NO_SHOW_GRACE_MINUTES)%')]
        private int $noShowGraceMinutes = 30,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();

        $tickets = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->andWhere('t.status = :pending')
            ->andWhere('r.paymentStatus = :paid')
            ->andWhere('tr.departureTime <= :noShowCutoff')
            ->andWhere('tr.status != :cancelled')
            ->setParameter('pending', 'en_attente')
            ->setParameter('paid', 'paye')
            ->setParameter(
                'noShowCutoff',
                $now->modify(
                    sprintf(
                        '-%d minutes',
                        max(0, $this->noShowGraceMinutes)
                    )
                )
            )
            ->setParameter('cancelled', 'annule')
            ->orderBy('tr.departureTime', 'ASC')
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($tickets as $ticket) {
            $notification = null;
            $connection = $this->em->getConnection();
            $connection->beginTransaction();
            try {
                $locked = $this->em->getRepository(Ticket::class)->find($ticket->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$locked || $locked->getStatus() !== 'en_attente') {
                    $connection->rollBack();
                    continue;
                }

                $previousStatus = $locked->getStatus();
                $this->stateTransitions->transitionTicket($locked, 'no_show');
                $locked->setQrCodeToken(null);
                $reservation = $locked->getReservation();

                // Ne jamais laisser un billet passer en no_show sans
                // contrepartie financière : si aucune transaction
                // RESERVATION_PAYMENT n'existe pour cette réservation,
                // processTicketNoShow() renvoie null silencieusement. Sans
                // ce contrôle, le billet serait quand même finalisé et les
                // fonds resteraient bloqués indéfiniment, sans jamais être
                // libérés ni reversés à la plateforme — une anomalie que
                // seul un audit ultérieur (FinancialWorkflowAuditService)
                // aurait fini par détecter, bien après coup.
                $noShowTx = $this->walletService->processTicketNoShow($locked);
                if ($noShowTx === null) {
                    throw new \RuntimeException(sprintf(
                        'Billet #%d : aucune transaction RESERVATION_PAYMENT trouvée pour la réservation #%d, impossible de finaliser financièrement ce no-show.',
                        $locked->getId(),
                        $reservation?->getId() ?? 0
                    ));
                }

                $this->auditLogger->record(
                    'TICKET_NO_SHOW',
                    'Ticket',
                    (string) $locked->getId(),
                    ['status' => $previousStatus],
                    ['status' => $locked->getStatus()],
                    [
                        'source' => 'trips.finalize-no-shows',
                        'reservationId' => $reservation?->getId(),
                        'tripId' => $reservation?->getTrip()?->getId(),
                        'settlementAmount' => $locked->getSettlementAmount(),
                        'walletTransactionId' => $noShowTx->getId(),
                    ]
                );

                if ($reservation) {
                    $all = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
                    $allFinal = !empty($all) && count(array_filter($all, static fn(Ticket $t) => in_array($t->getStatus(), ['embarque', 'annule', 'no_show'], true))) === count($all);
                    if ($allFinal && !in_array($reservation->getPaymentStatus(), ['rembourse', 'annule'], true)) {
                        $this->stateTransitions->transitionReservationPayment($reservation, 'no_show');
                    }

                    $notification = new Notification();
                    $notification->setRecipientType('user')
                        ->setRecipientId($reservation->getUser()?->getId())
                        ->setTitle('Voyage clôturé')
                        ->setContent(sprintf('Le billet #%d a été marqué comme non présenté.', $locked->getId()))
                        ->setCategory('TRAVEL');
                    if ($reservation->getUser()) {
                        $this->em->persist($notification);
                    }
                }

                $this->em->persist($locked);
                if ($reservation) {
                    $this->em->persist($reservation);
                }
                $this->em->flush();
                $connection->commit();

                if ($notification !== null && $reservation?->getUser()) {
                    $this->broadcaster->broadcast($notification);
                }
                $count++;
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $output->writeln(sprintf('<error>Billet #%d : %s</error>', $ticket->getId(), $e->getMessage()));
            }
        }

        $output->writeln(sprintf('<info>%d billet(s) finalisé(s) en no-show.</info>', $count));
        return Command::SUCCESS;
    }
}