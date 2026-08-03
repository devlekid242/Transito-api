<?php

namespace App\Repository;

use App\Entity\FAQ;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FAQ>
 *
 * @method FAQ|null find($id, $lockMode = null, $lockVersion = null)
 * @method FAQ|null findOneBy(array $criteria, array $orderBy = null)
 * @method FAQ[]    findAll()
 * @method FAQ[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FAQRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FAQ::class);
    }

    public function save(FAQ $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(FAQ $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find active FAQs ordered by category and priority
     */
    public function findActiveOrderedByCategory(): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('f.category', 'ASC')
            ->addOrderBy('f.orderPriority', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find FAQs by category
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.category = :category')
            ->andWhere('f.isActive = :active')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->orderBy('f.orderPriority', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search FAQs by question or answer.
     *
     * Correction : la condition OR n'était pas parenthésée. En DQL comme en
     * SQL, AND est prioritaire sur OR, donc la requête originale
     *   f.isActive = :active AND f.question LIKE :query OR f.answer LIKE :query
     * était en réalité évaluée comme
     *   (f.isActive = :active AND f.question LIKE :query) OR (f.answer LIKE :query)
     * ce qui faisait remonter des FAQ INACTIVES dès que leur réponse
     * correspondait au terme recherché. La parenthèse explicite corrige la
     * fuite de données.
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.isActive = :active')
            ->andWhere('(f.question LIKE :query OR f.answer LIKE :query)')
            ->setParameter('active', true)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('f.category', 'ASC')
            ->addOrderBy('f.orderPriority', 'ASC')
            ->getQuery()
            ->getResult();
    }
}