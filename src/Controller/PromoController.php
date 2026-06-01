<?php

namespace App\Controller;

use App\Entity\PromoCode;
use App\Repository\PromoCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/promos')]
class PromoController extends AbstractController
{
    public function __construct(private PromoCodeRepository $promoRepository, private EntityManagerInterface $em) {}

    #[Route('/active', name: 'api_promos_active', methods: ['GET'])]
    public function active(): JsonResponse
    {
        $now = new \DateTime();
        $promos = $this->promoRepository->createQueryBuilder('p')
            ->where('p.isActive = 1')
            ->andWhere('p.validFrom IS NULL OR p.validFrom <= :now')
            ->andWhere('p.validUntil IS NULL OR p.validUntil >= :now')
            ->setParameter('now', $now)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json(array_map([$this, 'normalizePromo'], $promos));
    }

    #[Route('/validate', name: 'api_promos_validate', methods: ['GET'])]
    public function validate(Request $request): JsonResponse
    {
        $code = $request->query->get('code');
        $tripId = $request->query->get('trip_id');

        if (!$code) {
            return $this->json(['message' => 'Code promo requis.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $promo = $this->promoRepository->findOneBy(['code' => strtoupper($code), 'isActive' => 1]);
        if (!$promo || !$this->isValidPromo($promo)) {
            return $this->json(['valid' => false, 'message' => 'Code promo invalide ou expiré.']);
        }

        return $this->json([
            'valid' => true,
            'code' => $promo->getCode(),
            'discountType' => $promo->getDiscountType(),
            'discountValue' => (float)$promo->getDiscountValue(),
            'tripId' => $tripId,
        ]);
    }

    #[Route('/apply', name: 'api_promos_apply', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $promoCode = $data['promo_code'] ?? $data['promoCode'] ?? null;

        if (!$promoCode) {
            return $this->json(['message' => 'promo_code requis.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $promo = $this->promoRepository->findOneBy(['code' => strtoupper($promoCode), 'isActive' => 1]);
        if (!$promo || !$this->isValidPromo($promo)) {
            return $this->json(['success' => false, 'message' => 'Code promo invalide ou expiré.']);
        }

        $promo->incrementUses();
        $this->em->persist($promo);
        $this->em->flush();

        return $this->json(['success' => true, 'code' => $promo->getCode()]);
    }

    #[Route('/discount', name: 'api_promos_discount', methods: ['GET'])]
    public function discount(Request $request): JsonResponse
    {
        $code = $request->query->get('code');
        $amount = $request->query->get('amount');

        if (!$code || !is_numeric($amount)) {
            return $this->json(['message' => 'code et amount requis.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $promo = $this->promoRepository->findOneBy(['code' => strtoupper($code), 'isActive' => 1]);
        if (!$promo || !$this->isValidPromo($promo)) {
            return $this->json(['valid' => false, 'message' => 'Code promo invalide ou expiré.']);
        }

        $amount = (float)$amount;
        $discount = 0.0;

        if ($promo->getDiscountType() === 'percentage') {
            $discount = ($amount * (float)$promo->getDiscountValue()) / 100.0;
        } else {
            $discount = min($amount, (float)$promo->getDiscountValue());
        }

        return $this->json([
            'valid' => true,
            'code' => $promo->getCode(),
            'discount' => round($discount, 2),
            'finalAmount' => round(max(0, $amount - $discount), 2),
        ]);
    }

    #[Route('/my-codes', name: 'api_promos_my_codes', methods: ['GET'])]
    public function myCodes(): JsonResponse
    {
        $promos = $this->promoRepository->createQueryBuilder('p')
            ->where('p.isActive = 1')
            ->andWhere('p.validFrom IS NULL OR p.validFrom <= :now')
            ->andWhere('p.validUntil IS NULL OR p.validUntil >= :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json(array_map([$this, 'normalizePromo'], $promos));
    }

    private function normalizePromo(PromoCode $promo): array
    {
        return [
            'id' => $promo->getId(),
            'code' => $promo->getCode(),
            'discountType' => $promo->getDiscountType(),
            'discountValue' => (float)$promo->getDiscountValue(),
            'validFrom' => $promo->getValidFrom()?->format(\DateTimeInterface::ATOM),
            'validUntil' => $promo->getValidUntil()?->format(\DateTimeInterface::ATOM),
            'maxUses' => $promo->getMaxUses(),
            'currentUses' => $promo->getCurrentUses(),
            'isActive' => $promo->getIsActive() === 1,
        ];
    }

    private function isValidPromo(PromoCode $promo): bool
    {
        if ($promo->getIsActive() !== 1) {
            return false;
        }

        $now = new \DateTime();
        if ($promo->getValidFrom() && $promo->getValidFrom() > $now) {
            return false;
        }

        if ($promo->getValidUntil() && $promo->getValidUntil() < $now) {
            return false;
        }

        if ($promo->getMaxUses() !== null && $promo->getCurrentUses() >= $promo->getMaxUses()) {
            return false;
        }

        return true;
    }
}
