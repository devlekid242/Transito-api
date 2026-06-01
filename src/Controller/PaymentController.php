<?php

namespace App\Controller;

use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PaymentController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/api/payments/initiate', name: 'payments_initiate', methods: ['POST'])]
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

    #[Route('/api/payments/confirm', name: 'payments_confirm', methods: ['POST'])]
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

        $this->em->persist($log);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'transactionId' => $tx,
            'status' => $log->getStatus(),
            'message' => 'Payment confirmed'
        ], 200);
    }

    #[Route('/api/payments/history', name: 'payments_history', methods: ['GET'])]
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
        $out = array_map(function($l) {
            return [
                'id' => $l->getId(),
                'reservationId' => $l->getReservation()?->getId(),
                'amount' => $l->getAmount(),
                'operator' => $l->getOperator(),
                'reference' => $l->getReference(),
                'status' => $l->getStatus(),
                'createdAt' => $l->getCreatedAt()?->format('c')
            ];
        }, $logs);

        return new JsonResponse($out, 200);
    }

    #[Route('/api/payments/{id}', name: 'payments_detail', methods: ['GET'])]
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

    #[Route('/api/payments/{id}/refund', name: 'payments_refund', methods: ['POST'])]
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

        $this->em->persist($log);
        $this->em->flush();

        return new JsonResponse(['success' => true], 200);
    }

    #[Route('/api/payments/methods', name: 'payments_methods', methods: ['GET'])]
    public function methods(): JsonResponse
    {
        $methods = [
            ['id' => 'MTN_MOMO', 'name' => 'MTN Mobile Money'],
            ['id' => 'AIRTEL_MONEY', 'name' => 'Airtel Money'],
            ['id' => 'CARD', 'name' => 'Card (Visa/Mastercard)'],
        ];
        return new JsonResponse($methods, 200);
    }

    #[Route('/api/payments/validate-card', name: 'payments_validate_card', methods: ['POST'])]
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

    #[Route('/api/payments/transaction/{transactionId}', name: 'payments_transaction_status', methods: ['GET'])]
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
}
