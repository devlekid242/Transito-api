<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\PaymentLog;
use App\Entity\PaymentIntent;
use App\Entity\ReservationReschedule;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\User;
use App\Service\DomainStateTransitionService;
use App\Service\AuditLogger;
use App\Service\AdminNotificationService;
use App\Service\NotificationBroadcastService;
use App\Service\WalletService;
use App\Service\RescheduleService;
use App\Service\MobileMoney\MobileMoneyGatewayFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\DBAL\LockMode;
use App\Service\MobileMoney\Uuid4Generator;
use App\Service\MobileMoney\MobileMoneyTextSanitizer;

class PaymentController extends AbstractController
{

    public function __construct(
        private EntityManagerInterface $em,
        private NotificationBroadcastService $notificationBroadcaster,
        private AdminNotificationService $adminNotificationService,
        private WalletService $walletService,
        private DomainStateTransitionService $stateTransitions,
        private AuditLogger $auditLogger,
        private RescheduleService $rescheduleService,
        private MobileMoneyGatewayFactory $mobileMoneyGatewayFactory,
    ) {}


    /** Normalise un montant reçu en texte sans passer par un float. */
    private function normalizeMoneyAmount(mixed $value): ?string
    {
        if (is_int($value)) {
            return number_format($value, 2, '.', '');
        }
        if (is_float($value)) {
            // JSON numbers peuvent arriver en float ; on ne fait qu'un formatage
            // final, sans utiliser le float pour les opérations financières.
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return $whole . '.' . str_pad($fraction, 2, '0');
    }

    public function initiate(Request $request): JsonResponse
    {


        $data = json_decode($request->getContent(), true) ?? [];
        $reservationId = $data['reservationId'] ?? $data['reservation_id'] ?? null;
        $method = $data['paymentMethod'] ?? $data['payment_method'] ?? 'Mobile Money';
        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $idempotencyKey)) {
            return new JsonResponse(['error' => 'Idempotency-Key invalide.'], 400);
        }

        if (!$reservationId) {
            return new JsonResponse(['error' => 'reservationId is required'], 400);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], 401);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            // Une seule tentative PENDING peut exister pour une réservation.
            // Le verrou sur la réservation empêche deux appels simultanés
            // d'ouvrir deux paiements concurrents pour le même billet.
            $reservation = $this->em->getRepository(Reservation::class)->find($reservationId, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if (!$reservation) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Reservation not found'], 404);
            }
            if ($reservation->getUser()?->getId() !== $user->getId()) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Vous n’êtes pas autorisé à initier le paiement de cette réservation.'], 403);
            }
            if ($reservation->getPaymentStatus() !== 'en_attente') {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Cette réservation n’est plus payable.'], 409);
            }
            if ($reservation->isPaymentExpired()) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Le délai de paiement de cette réservation est expiré.'], 409);
            }

            if ($idempotencyKey !== '') {
                $existingByKey = $this->em->getRepository(PaymentLog::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
                if ($existingByKey) {
                    if (
                        $existingByKey->getReservation()?->getId() !== $reservation->getId()
                        || $existingByKey->getUser()?->getId() !== $user->getId()
                    ) {
                        $connection->rollBack();
                        return new JsonResponse(['error' => 'Cette Idempotency-Key est déjà utilisée pour une autre opération.'], 409);
                    }
                    $connection->commit();
                    return new JsonResponse([
                        'success' => true,
                        'transactionId' => $existingByKey->getReference(),
                        'status' => $existingByKey->getStatus(),
                        'paymentLogId' => $existingByKey->getId(),
                        'idempotent' => true,
                    ], 200);
                }
            }

            $existing = $this->em->getRepository(PaymentLog::class)->findOneBy([
                'reservation' => $reservation,
                'status' => 'PENDING',
            ]);
            if ($existing) {
                $connection->commit();
                return new JsonResponse([
                    'success' => true,
                    'transactionId' => $existing->getReference(),
                    'status' => $existing->getStatus(),
                    'paymentLogId' => $existing->getId(),
                    'idempotent' => true,
                ], 200);
            }

            // 👈 CORRIGÉ (audit intégrité) : le montant du PaymentLog était
            // auparavant celui envoyé par le client (`$data['amount']`), non
            // vérifié. Le crédit réel du portefeuille (creditForReservationPayment)
            // se base bien sur reservation.totalAmount côté serveur, donc le
            // grand livre lui-même n'était pas corruptible — mais le PaymentLog
            // (utilisé pour les reçus, l'historique et la réconciliation admin)
            // pouvait afficher un montant différent de ce qui était réellement dû.
            // On recalcule désormais TOUJOURS depuis la réservation, seule
            // source de vérité, comme le fait déjà BookingController::create().
            $amount = $reservation->getTotalAmount();

            $log = new PaymentLog();
            $log->setReservation($reservation);
            $log->setUser($user);
            $log->setIdempotencyKey($idempotencyKey !== '' ? $idempotencyKey : null);
            $log->setOperator($method);
            $reference = Uuid4Generator::generate();
            $log->setReference($reference);
            $log->setAmount((string)$amount);
            $log->setStatus('PENDING');
            $log->setRawResponse(null);

            $this->em->persist($log);
            $this->em->flush();
            $connection->commit();

            // 👈 NOUVEAU : déclenche le push USSD / demande de paiement réelle.
            // Le PaymentLog reste PENDING : seul le webhook (ou le polling en
            // sandbox, voir PollMobileMoneyPaymentsCommand) le fera passer à
            // SUCCESS/FAILED — comme le fait déjà remarquer le commentaire existant
            // juste au-dessus de confirm().
            try {
                $gateway = $this->mobileMoneyGatewayFactory->get($method);
                $gateway->requestToPay(
                    referenceId: $reference,               // même valeur que PaymentLog::reference
                    amount: (string) $amount,
                    msisdn: $reservation->getPaymentPhone(),
                    externalId: $reference,
                    payerMessage: MobileMoneyTextSanitizer::sanitize(sprintf('Reservation #%d', $reservation->getId())),
                    payeeNote: 'Transito',
                );
            } catch (\App\Service\MobileMoney\MobileMoneyException $e) {
                // On ne fait PAS échouer la création du PaymentLog pour un souci
                // réseau ponctuel : il reste PENDING et sera repris par le polling.
                // On journalise pour investigation si ça persiste.
                // $this->logger->error('MoMo requestToPay a échoué pour ' . $reference . ': ' . $e->getMessage(), [
                //     'exception' => $e,
                // ]);
                error_log('MoMo requestToPay a échoué pour ' . $reference . ': ' . $e->getMessage());
                return new JsonResponse(['success' => false,'message' => "un probleme un survenue lors de la creation de requestTopaie ,". $reference . ': ' . $e->getMessage()],400);
            }

            // NB : à ce stade, la réservation est en attente de paiement (payment_status = 'en_attente').
            // Elle ne doit JAMAIS être comptabilisée dans le chiffre d'affaires ni dans le solde d'une
            // agence tant que confirm() n'a pas marqué le PaymentLog en SUCCESS. C'était la source de
            // l'incohérence signalée : des réservations non payées gonflaient les stats du partenaire.

            return new JsonResponse([
                'success' => true,
                'transactionId' => $reference,
                'status' => $log->getStatus(),
                'paymentLogId' => $log->getId()
            ], 201);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retourne uniquement l'état serveur du paiement. Le client ne peut jamais
     * transformer PENDING en SUCCESS : cette transition appartient exclusivement
     * au webhook signé / au futur adaptateur serveur MTN-Airtel.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $tx = $data['transaction_id'] ?? $data['transactionId'] ?? null;
        if (!$tx) {
            return new JsonResponse(['error' => 'transaction_id is required'], 400);
        }

        $log = $this->em->getRepository(PaymentLog::class)->findOneBy(['reference' => $tx]);
        if (!$log) {
            return new JsonResponse(['error' => 'Transaction not found'], 404);
        }

        $currentUser = $this->getUser();
        $reservation = $log->getReservation();
        if (!$currentUser instanceof User || !$reservation || $reservation->getUser()?->getId() !== $currentUser->getId()) {
            return new JsonResponse(['error' => 'Vous n’êtes pas autorisé à consulter ce paiement.'], 403);
        }

        // IMPORTANT : le client ne peut jamais transformer lui-même PENDING en SUCCESS.
        // Le statut SUCCESS est réservé au webhook signé / traitement serveur du prestataire.
        return new JsonResponse([
            'success' => true,
            'transactionId' => $tx,
            'status' => $log->getStatus(),
            'message' => $log->getStatus() === 'SUCCESS'
                ? 'Payment confirmed by provider.'
                : 'Payment is still awaiting provider confirmation.'
        ], 200);
    }

    #[Route('/api/payments/webhook', name: 'api_payments_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) ($_ENV['TRANSITO_PAYMENT_WEBHOOK_SECRET'] ?? $_SERVER['TRANSITO_PAYMENT_WEBHOOK_SECRET'] ?? '');
        if ($secret === '') {
            return new JsonResponse(['error' => 'Payment webhook is not configured.'], 503);
        }

        $rawBody = $request->getContent();
        $signature = (string) $request->headers->get('X-Transito-Signature', '');
        $timestamp = (string) $request->headers->get('X-Transito-Timestamp', '');
        if ($signature === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return new JsonResponse(['error' => 'Invalid webhook authentication.'], 401);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            return new JsonResponse(['error' => 'Invalid webhook signature.'], 401);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], 400);
        }

        $reference = $data['reference'] ?? $data['transactionId'] ?? null;
        $providerReference = $data['providerReference'] ?? $data['provider_reference'] ?? null;
        $status = strtoupper((string) ($data['status'] ?? ''));
        $amount = $this->normalizeMoneyAmount($data['amount'] ?? null);
        $paidAtRaw = $data['paidAt'] ?? $data['paid_at'] ?? null;
        if (!$reference || !$providerReference || !$amount || !$paidAtRaw || !in_array($status, ['SUCCESS', 'FAILED'], true)) {
            return new JsonResponse(['error' => 'reference, providerReference, amount, paidAt and a valid status are required.'], 400);
        }

        // Les reports utilisent un PaymentIntent distinct : ils ne doivent
        // jamais passer dans le flux PaymentLog d'une réservation initiale.
        $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy(['reference' => $reference]);
        if ($intent) {
            if (bccomp($intent->getAmount(), $amount, 2) !== 0) {
                return new JsonResponse(['error' => 'Payment intent amount mismatch.'], 409);
            }
            if ($intent->getStatus() === PaymentIntent::STATUS_SUCCESS) {
                return new JsonResponse(['success' => true, 'status' => 'SUCCESS', 'idempotent' => true], 200);
            }
            $connection = $this->em->getConnection();
            $connection->beginTransaction();
            try {
                $intent = $this->em->getRepository(PaymentIntent::class)->find($intent->getId(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                if (!$intent) throw new \RuntimeException('Payment intent not found.');
                $adjustment = $intent->getReschedule();
                if (!$adjustment || $adjustment->getStatus() !== ReservationReschedule::STATUS_PAYMENT_PENDING) {
                    throw new \RuntimeException('Le report associé n’est plus en attente de paiement.');
                }
                if ($adjustment->getQuoteExpiresAt() && $adjustment->getQuoteExpiresAt() < new \DateTimeImmutable()) {
                    throw new \RuntimeException('Le devis de report a expiré.');
                }
                $intent->setProviderReference((string) $providerReference)->setRawResponse($rawBody)->setProcessedAt(new \DateTimeImmutable())->setStatus($status);
                if ($status === 'FAILED') {
                    $adjustment->setStatus(ReservationReschedule::STATUS_FAILED);
                    $this->em->persist($intent);
                    $this->em->persist($adjustment);
                    $this->em->flush();
                    $connection->commit();
                    return new JsonResponse(['success' => true, 'status' => 'FAILED'], 200);
                }
                $reservation = $intent->getReservation();
                if (!$reservation) throw new \RuntimeException('Réservation du report introuvable.');
                $this->walletService->applyRescheduleAdjustment($reservation, $adjustment->getDifference());
                $this->em->persist($intent);
                $this->rescheduleService->finalize($adjustment);
                $this->em->flush();
                $this->auditLogger->record(
                    'RESCHEDULE_PAYMENT_SUCCEEDED',
                    'PaymentIntent',
                    (string) $intent->getId(),
                    ['status' => PaymentIntent::STATUS_PENDING],
                    ['status' => PaymentIntent::STATUS_SUCCESS],
                    ['reservationId' => $reservation->getId(), 'rescheduleId' => $adjustment->getId(), 'amount' => $amount]
                );
                $this->em->flush();
                $connection->commit();
                return new JsonResponse(['success' => true, 'status' => 'SUCCESS', 'rescheduleId' => $adjustment->getId()], 200);
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) $connection->rollBack();
                return new JsonResponse(['error' => $e->getMessage()], 409);
            }
        }

        $log = $this->em->getRepository(PaymentLog::class)->findOneBy(['reference' => $reference]);
        if (!$log) {
            return new JsonResponse(['error' => 'Transaction not found.'], 404);
        }

        $reservation = $log->getReservation();
        if (!$reservation) {
            return new JsonResponse(['error' => 'Payment is not linked to a reservation.'], 409);
        }

        $sameProviderLog = $this->em->getRepository(PaymentLog::class)->findOneBy(['providerReference' => (string) $providerReference]);
        if ($sameProviderLog && $sameProviderLog->getId() !== $log->getId()) {
            return new JsonResponse(['error' => 'Provider reference already belongs to another payment.'], 409);
        }

        if (bccomp((string) $log->getAmount(), $amount, 2) !== 0) {
            return new JsonResponse(['error' => 'Payment amount mismatch.'], 409);
        }

        try {
            $paidAt = new \DateTimeImmutable($paidAtRaw);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Invalid paidAt.'], 400);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $notification = null;
        try {
            // Reload under a database lock to make provider retries idempotent.
            $log = $this->em->createQueryBuilder()
                ->select('p')
                ->from(PaymentLog::class, 'p')
                ->where('p.id = :id')
                ->setParameter('id', $log->getId())
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleResult();

            // Verrouiller aussi la réservation : deux PaymentLog différents ne doivent
            // jamais pouvoir créditer deux fois la même réservation en parallèle.
            $reservation = $this->em->getRepository(Reservation::class)->find($reservation->getId(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if (!$reservation) {
                throw new \RuntimeException('Reservation disappeared during payment processing.');
            }

            // A duplicate webhook est acquitté sans recréditer le wallet.
            if ($log->getStatus() === 'SUCCESS') {
                $connection->commit();
                return new JsonResponse(['success' => true, 'status' => 'SUCCESS', 'idempotent' => true], 200);
            }
            if (in_array($log->getStatus(), ['FAILED', 'DUPLICATE'], true)) {
                $connection->commit();
                return new JsonResponse(['success' => true, 'status' => $log->getStatus(), 'idempotent' => true], 200);
            }

            // Les contrôles temporels concernent uniquement un SUCCESS. Un FAILED
            // reçu après expiration doit quand même pouvoir clôturer proprement
            // la tentative de paiement.
            if ($status === 'SUCCESS') {
                if ($reservation->getPaymentExpiresAt() && $paidAt > \DateTimeImmutable::createFromMutable($reservation->getPaymentExpiresAt())) {
                    $connection->rollBack();
                    return new JsonResponse(['error' => 'Payment was completed after the reservation payment window expired.'], 409);
                }
                $reservationCreatedAt = $reservation->getCreatedAt();
                if ($reservationCreatedAt && $paidAt < (new \DateTimeImmutable($reservationCreatedAt->format('c')))->modify('-5 minutes')) {
                    $connection->rollBack();
                    return new JsonResponse(['error' => 'Payment timestamp predates the reservation.'], 409);
                }
            }

            // Une réservation déjà payée par une autre tentative est un paiement
            // concurrent : surtout ne pas créditer une deuxième fois. On conserve
            // la notification opérationnelle via le statut DUPLICATE.
            if ($reservation->getPaymentStatus() === 'paye') {
                $log->setProviderReference((string) $providerReference);
                $log->setRawResponse($rawBody);
                $log->setProcessedAt(new \DateTime());
                $log->setStatus('DUPLICATE');
                $this->em->persist($log);
                $this->em->flush();
                $connection->commit();
                $this->adminNotificationService->notifyEvent(
                    'Paiement concurrent détecté',
                    sprintf('Le paiement %s a été reçu alors que la réservation #%d était déjà payée.', $providerReference, $reservation->getId()),
                    'PAYMENT_DUPLICATE',
                    ['reservationId' => $reservation->getId(), 'paymentLogId' => $log->getId(), 'providerReference' => $providerReference]
                );
                return new JsonResponse(['success' => true, 'status' => 'DUPLICATE'], 200);
            }

            $log->setProviderReference((string) $providerReference);
            $log->setRawResponse($rawBody);
            $log->setProcessedAt(new \DateTime());
            $log->setStatus($status);

            if ($status === 'FAILED') {
                $this->stateTransitions->transitionReservationPayment($reservation, 'echoue');
                $this->em->persist($reservation);
                $this->em->persist($log);
                $this->em->flush();
                $connection->commit();
                return new JsonResponse(['success' => true, 'status' => 'FAILED'], 200);
            }

            if (in_array($reservation->getPaymentStatus(), ['annule', 'rembourse'], true)) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Reservation is already cancelled or refunded.'], 409);
            }

            $this->stateTransitions->transitionReservationPayment($reservation, 'paye');
            $this->em->persist($reservation);
            $this->walletService->creditForReservationPayment($reservation);
            $this->em->persist($log);

            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($reservation->getUser()?->getId())
                ->setTitle('Paiement confirmé')
                ->setContent(sprintf('Votre paiement pour la réservation #%d a été confirmé.', $reservation->getId()))
                ->setCategory('PAYMENT');
            $this->em->persist($notification);

            $this->em->flush();
            $this->auditLogger->record(
                'PAYMENT_SUCCEEDED',
                'PaymentLog',
                (string) $log->getId(),
                ['status' => 'PENDING', 'reservationPaymentStatus' => 'en_attente'],
                ['status' => $log->getStatus(), 'reservationPaymentStatus' => $reservation->getPaymentStatus()],
                ['source' => 'payment.webhook', 'reservationId' => $reservation->getId(), 'providerReference' => $providerReference, 'amount' => $amount]
            );
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }

        try {
            if ($notification) {
                $this->notificationBroadcaster->broadcast($notification);
            }
            $this->adminNotificationService->notifyEvent(
                'Paiement confirmé',
                sprintf('Le paiement de la réservation #%d a été confirmé par le prestataire.', $reservation->getId()),
                'PAYMENT',
                ['reservationId' => $reservation->getId(), 'paymentLogId' => $log->getId(), 'providerReference' => $providerReference]
            );
        } catch (\Throwable) {
            // Le commit financier est déjà effectué. Une panne Pusher/FCM ne doit
            // jamais faire croire au prestataire que le paiement a échoué.
        }

        return new JsonResponse(['success' => true, 'status' => 'SUCCESS'], 200);
    }

    public function history(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([], 200);
        }

        $qb = $this->em->getRepository(PaymentLog::class)->createQueryBuilder('p')
            ->join('p.reservation', 'r')
            ->join('r.user', 'u')
            ->where('u.id = :uid')
            ->setParameter('uid', $user->getId())
            ->orderBy('p.createdAt', 'DESC');

        $logs = $qb->getQuery()->getResult();
        $out = array_map(function ($l) {
            return [
                'id' => $l->getId(),
                'reservationId' => $l->getReservation()?->getId(),
                'amount' => $l->getAmount(),
                'paymentMethod' => $l->getOperator(),
                'reference' => $l->getReference(),
                'status' => $l->getStatus(),
                'createdAt' => $l->getCreatedAt()?->format('c')
            ];
        }, $logs);

        return new JsonResponse($out, 200);
    }

    public function methods(): JsonResponse
    {
        $methods = [
            ['id' => 'MTN_MOMO', 'name' => 'MTN Mobile Money'],
            ['id' => 'AIRTEL_MONEY', 'name' => 'Airtel Money'],
            ['id' => 'CARD', 'name' => 'Card (Visa/Mastercard)'],
        ];
        return new JsonResponse($methods, 200);
    }

    /**
     * Liste des transactions de remboursement en attente de traitement par
     * l'administration, générées automatiquement par BookingController::cancel().
     * Permet à l'admin de retrouver rapidement les annulations clients à rembourser.
     */
    #[Route('/api/payments/refunds/pending', name: 'payments_pending_refunds', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function pendingRefunds(): JsonResponse
    {
        $qb = $this->em->getRepository(PaymentLog::class)->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', 'REFUND_PENDING')
            ->orderBy('p.createdAt', 'ASC');

        $logs = $qb->getQuery()->getResult();

        $out = array_map(function (PaymentLog $l) {
            $reservation = $l->getReservation();
            $trip = $reservation?->getTrip();
            $rawResponse = json_decode($l->getRawResponse() ?? '{}', true);

            return [
                'paymentLogId' => $l->getId(),
                'reference' => $l->getReference(),
                'amount' => $l->getAmount(),
                'operator' => $l->getOperator(),
                'reservationId' => $reservation?->getId(),
                'paymentPhone' => $reservation?->getPaymentPhone(),
                'trip' => $trip ? [
                    'departureCity' => $trip->getDepartureCity(),
                    'arrivalCity' => $trip->getArrivalCity(),
                    'departureTime' => $trip->getDepartureTime()?->format('c'),
                ] : null,
                'reason' => $rawResponse['reason'] ?? null,
                'requestedAt' => $rawResponse['requested_at'] ?? $l->getCreatedAt()?->format('c'),
                'createdAt' => $l->getCreatedAt()?->format('c'),
            ];
        }, $logs);

        return new JsonResponse($out, 200);
    }

    /**
     * 👈 CORRIGÉ (audit sécurité — IDOR) : cette action ne vérifiait
     * auparavant AUCUNE propriété ni rôle, contrairement à history() qui
     * scope correctement sur l'utilisateur connecté. N'importe quel
     * utilisateur authentifié pouvait donc consulter le PaymentLog (y
     * compris le rawResponse brut de l'opérateur) de n'importe quelle
     * réservation en devinant/énumérant des id.
     */
    public function detail(int $id): JsonResponse
    {
        $log = $this->em->getRepository(PaymentLog::class)->find($id);
        if (!$log) return new JsonResponse(['error' => 'Not found'], 404);

        $currentUser = $this->getUser();
        $isOwner = $currentUser instanceof User
            && $log->getReservation()?->getUser()
            && $log->getReservation()->getUser()->getId() === $currentUser->getId();
        $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_ADMIN');

        if (!$isOwner && !$isAdmin) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        return new JsonResponse([
            'id' => $log->getId(),
            'reservationId' => $log->getReservation()?->getId(),
            'amount' => $log->getAmount(),
            'operator' => $log->getOperator(),
            'reference' => $log->getReference(),
            'status' => $log->getStatus(),
            // rawResponse (payload brut de l'opérateur) réservé aux admins
            'rawResponse' => $isAdmin ? $log->getRawResponse() : null,
            'createdAt' => $log->getCreatedAt()?->format('c')
        ], 200);
    }

    /**
     * 👈 CORRIGÉ (audit sécurité) : action réservée aux admins désormais.
     * Elle déclenche un débit réel du portefeuille d'une agence
     * (WalletService::debitForRefund) — elle ne doit en aucun cas être
     * accessible à un utilisateur standard.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function refund(int $id, Request $request): JsonResponse
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $log = $this->em->getRepository(PaymentLog::class)->find($id);
            if (!$log) return new JsonResponse(['error' => 'Not found'], 404);

            // 👈 NOUVEAU : ne rembourser qu'une transaction réellement payée
            // (SUCCESS) ou explicitement en attente de remboursement
            // (REFUND_PENDING, cas normal issu de BookingController::cancel()).
            // Empêche de "rembourser" un log encore PENDING (jamais confirmé) ou
            // déjà REFUNDED/FAILED.
            $refundableStatuses = ['SUCCESS', 'REFUND_PENDING'];
            if (!in_array($log->getStatus(), $refundableStatuses, true)) {
                return new JsonResponse([
                    'error' => sprintf('Cette transaction (statut: %s) ne peut pas être remboursée.', $log->getStatus()),
                ], 409);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $reason = $data['reason'] ?? 'requested_by_user';

            $log->setStatus('REFUNDED');
            $log->setProcessedAt(new \DateTime());
            $raw = json_decode($log->getRawResponse() ?? '{}', true);
            $raw['refund'] = ['reason' => $reason, 'at' => (new \DateTime())->format('c')];
            $log->setRawResponse(json_encode($raw));

            // mark reservation as refunded
            $reservation = $log->getReservation();
            if ($reservation) {
                // 👈 SÉCURITÉ : ne jamais rembourser une réservation dont un billet a déjà été validé/embarqué.
                // Un passager qui est monté dans le bus ne peut pas être remboursé.
                $existingTickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
                $hasBoardedTicket = false;
                foreach ($existingTickets as $ticket) {
                    if ($ticket->getStatus() === 'embarque') {
                        $hasBoardedTicket = true;
                        break;
                    }
                }
                if ($hasBoardedTicket) {
                    return new JsonResponse([
                        'error' => "Impossible de rembourser : au moins un billet de cette réservation a déjà été validé à l'embarquement.",
                    ], 409);
                }

                $this->stateTransitions->transitionReservationPayment($reservation, 'rembourse');
                $this->em->persist($reservation);

                // Si l'agence avait déjà été créditée pour cette réservation, on retire
                // le montant net de son portefeuille (voir WalletService::debitForRefund).
                $this->walletService->debitForRefund($reservation, $reason);
            }

            if ($reservation?->getUser()) {
                $notification = new Notification();
                $notification->setRecipientType('user')
                    ->setRecipientId($reservation->getUser()->getId())
                    ->setTitle('Remboursement effectué')
                    ->setContent(sprintf('Le remboursement de votre réservation #%d a été traité.', $reservation->getId()))
                    ->setCategory('PAYMENT');
                $this->em->persist($notification);
            }

            $this->em->persist($log);
            $this->em->flush();
            $connection->commit();

            if (isset($notification)) {
                $this->notificationBroadcaster->broadcast($notification);
            }

            return new JsonResponse(['success' => true], 200);
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }



    public function validateCard(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $number = preg_replace('/\s+/', '', ($data['card_number'] ?? ''));
        $expiryMonth = $data['expiry_month'] ?? null;
        $expiryYear = $data['expiry_year'] ?? null;

        if (!$number || !$expiryMonth || !$expiryYear) {
            return new JsonResponse(['valid' => false, 'reason' => 'missing_fields'], 400);
        }

        $isValid = $this->luhnCheck($number);
        return new JsonResponse(['valid' => $isValid], 200);
    }

    public function transactionStatus(string $transactionId): JsonResponse
    {
        $log = $this->em->getRepository(PaymentLog::class)->findOneBy(['reference' => $transactionId]);
        if (!$log) return new JsonResponse(['error' => 'Not found'], 404);

        $user = $this->getUser();
        $reservation = $log->getReservation();
        if (!$user instanceof User || !$reservation || $reservation->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        return new JsonResponse([
            'transactionId' => $transactionId,
            'status' => $log->getStatus(),
            'amount' => (string) $log->getAmount(),
            'createdAt' => $log->getCreatedAt()?->format('c')
        ], 200);
    }
    private function luhnCheck(string $number): bool
    {
        $digits = array_reverse(str_split($number));
        $sum = 0;
        foreach ($digits as $i => $d) {
            $n = (int)$d;
            if ($i % 2 === 1) {
                $n *= 2;
                if ($n > 9) $n -= 9;
            }
            $sum += $n;
        }
        return $sum % 10 === 0;
    }

    #[Route('/api/payments/{id}/receipt', name: 'payment_receipt', methods: ['GET'])]
    public function paymentReceipt(int $id): Response
    {
        $log = $this->em->getRepository(PaymentLog::class)->find($id);
        if (!$log) return new Response('Not found', 404);

        $user = $this->getUser();
        $reservation = $log->getReservation();
        $isAdmin = $user instanceof User && $this->isGranted('ROLE_ADMIN');
        $isOwner = $user instanceof User && $reservation?->getUser()?->getId() === $user->getId();
        if (!$isOwner && !$isAdmin) return new Response('Not found', 404);

        $content = "Payment Receipt #{$log->getId()}\n";
        $content .= "Reservation: " . ($log->getReservation()?->getId() ?? 'N/A') . "\n";
        $content .= "Amount: " . $log->getAmount() . "\n";
        $content .= "Status: " . $log->getStatus() . "\n";

        return new Response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="receipt_payment_%d.pdf"', $log->getId()),
        ]);
    }
}
