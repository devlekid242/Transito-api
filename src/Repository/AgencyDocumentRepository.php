<?php

namespace App\Repository;

use App\Entity\AgencyDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgencyDocument>
 *
 * @method AgencyDocument|null find($id, $lockMode = null, $lockVersion = null)
 * @method AgencyDocument|null findOneBy(array $criteria, array $orderBy = null)
 * @method AgencyDocument[]    findAll()
 * @method AgencyDocument[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AgencyDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgencyDocument::class);
    }
}
