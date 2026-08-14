<?php

namespace App\Controller;

use App\Repository\FAQRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Public, read-only FAQ API used by Client/Agent/Partner applications. */
#[Route('/api/faqs')]
final class FaqController extends AbstractController
{
    public function __construct(private FAQRepository $repository) {}

    #[Route('', name: 'api_public_faqs', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $category = trim((string) $request->query->get('category', ''));
        $search = trim((string) $request->query->get('search', ''));
        $limit = max(1, min(100, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $qb = $this->repository->createQueryBuilder('f')
            ->andWhere('f.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('f.category', 'ASC')
            ->addOrderBy('f.orderPriority', 'ASC')
            ->addOrderBy('f.id', 'ASC');

        if ($category !== '') {
            $qb->andWhere('f.category = :category')->setParameter('category', $category);
        }

        if ($search !== '') {
            $qb->andWhere('(LOWER(f.question) LIKE LOWER(:search) OR LOWER(f.answer) LIKE LOWER(:search))')
                ->setParameter('search', '%' . $search . '%');
        }

        $faqs = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        $data = array_map(static fn ($faq) => [
            'id' => $faq->getId(),
            'question' => $faq->getQuestion(),
            'answer' => $faq->getAnswer(),
            'category' => $faq->getCategory(),
            'orderPriority' => $faq->getOrderPriority(),
        ], $faqs);

        return $this->json([
            'data' => $data,
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($data),
        ]);
    }
}
