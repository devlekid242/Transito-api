<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\User;
use App\Service\NotificationBroadcastService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PaymentController extends AbstractController
{

    public function __construct(private EntityManagerInterface $em, private NotificationBroadcastService $notificationBroadcaster) {}


    public function initiate(Request $request): JsonResponse
    {


        $data = json_decode($request->getContent(), true) ?? [];
        $reservationId = $data['reservationId'] ?? $data['reservation_id'] ?? null;
        $amount = $data['amount'] ?? null;
        $method = $data['paymentMethod'] ?? $data['payment_method'] ?? 'Mobile Money';

        if (!$reservationId || !$amount) {
            return new JsonResponse(['error' => 'reservationId and amount are required'], 400);
        }

        $reservation = $this->em->getRepository(Reservation::class)->find($reservationId);
        if (!$reservation) return new JsonResponse(['error' => 'Reservation not found'], 404);

        $log = new PaymentLog();
        $log->setReservation($reservation);
        $log->setOperator($method);
        $reference = uniqid('pay_', true);
        $log->setReference($reference);
        $log->setAmount((string)$amount);
        $log->setStatus('PENDING');
        $log->setRawResponse(null);

        $this->em->persist($log);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'transactionId' => $reference,
            'status' => $log->getStatus(),
            'paymentLogId' => $log->getId()
        ], 201);
    }

    public function confirm(Request $request): JsonResponse
    {

        $data = json_decode($request->getContent(), true) ?? [];
        $tx = $data['transaction_id'] ?? $data['transactionId'] ?? null;
        if (!$tx) return new JsonResponse(['error' => 'transaction_id is required'], 400);

        $repo = $this->em->getRepository(PaymentLog::class);
        $log = $repo->findOneBy(['reference' => $tx]);
        if (!$log) return new JsonResponse(['error' => 'Transaction not found'], 404);

        // Simulate confirmation: mark success unless explicitly failed
        $log->setStatus('SUCCESS');
        $raw = ['confirmed_at' => (new \DateTime())->format('c'), 'payload' => $data];
        $log->setRawResponse(json_encode($raw));

        // update reservation payment status
        $reservation = $log->getReservation();
        if ($reservation) {
            $reservation->setPaymentStatus('paye');
            $this->em->persist($reservation);
        }

        if ($reservation?->getUser()) {
            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($reservation->getUser()->getId())
                ->setTitle('Paiement confirmé')
                ->setContent(sprintf('Votre paiement pour la réservation #%d a été confirmé.', $reservation->getId()))
                ->setCategory('PAYMENT');
            $this->em->persist($notification);
        }

        $this->em->persist($log);
        $this->em->flush();

        if (isset($notification)) {
            $this->notificationBroadcaster->broadcast($notification);
        }

        return new JsonResponse([
            'success' => true,
            'transactionId' => $tx,
            'status' => $log->getStatus(),
            'message' => 'Payment confirmed'
        ], 200);
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

    public function detail(int $id): JsonResponse

    {
        $log = $this->em->getRepository(PaymentLog::class)->find($id);
        if (!$log) return new JsonResponse(['error' => 'Not found'], 404);

        return new JsonResponse([
            'id' => $log->getId(),
            'reservationId' => $log->getReservation()?->getId(),
            'amount' => $log->getAmount(),
            'operator' => $log->getOperator(),
            'reference' => $log->getReference(),
            'status' => $log->getStatus(),
            'rawResponse' => $log->getRawResponse(),
            'createdAt' => $log->getCreatedAt()?->format('c')
        ], 200);
    }

    public function refund(int $id, Request $request): JsonResponse

    {
        $log = $this->em->getRepository(PaymentLog::class)->find($id);
        if (!$log) return new JsonResponse(['error' => 'Not found'], 404);
        $data = json_decode($request->getContent(), true) ?? [];
        $reason = $data['reason'] ?? 'requested_by_user';

        $log->setStatus('REFUNDED');
        $raw = json_decode($log->getRawResponse() ?? '{}', true);
        $raw['refund'] = ['reason' => $reason, 'at' => (new \DateTime())->format('c')];
        $log->setRawResponse(json_encode($raw));

        // mark reservation as refunded
        $reservation = $log->getReservation();
        if ($reservation) {
            $reservation->setPaymentStatus('rembourse');
            $this->em->persist($reservation);
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

        if (isset($notification)) {
            $this->notificationBroadcaster->broadcast($notification);
        }

        return new JsonResponse(['success' => true], 200);
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
        return new JsonResponse(['transactionId' => $transactionId, 'status' => $log->getStatus(), 'createdAt' => $log->getCreatedAt()?->format('c')], 200);
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