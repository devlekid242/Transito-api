<?php

namespace App\Repository;

use App\Entity\Agency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agency>
 */
class AgencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agency::class);
    }

    /**
     * Get KYC status distribution across agencies.
     * KYC status is derived from agency documents.
     * Returns array with status as key and count as value.
     */
    public function getKycStatusDistribution(): array
    {
        // Get all agencies with their document counts
        $agencies = $this->findAll();
        
        $distribution = [
            'verified' => 0,
            'pending' => 0,
            'missing' => 0,
            'rejected' => 0,
        ];
        
        foreach ($agencies as $agency) {
            $documents = $agency->getDocuments();
            $kycStatus = $this->getAgencyKycStatus($documents);
            $distribution[$kycStatus]++;
        }
        
        return $distribution;
    }

    /**
     * Determine KYC status based on agency documents.
     */
    private function getAgencyKycStatus($documents): string
    {
        if ($documents->isEmpty()) {
            return 'missing';
        }
        
        $hasRejected = false;
        $hasApproved = false;
        $hasPending = false;
        
        foreach ($documents as $doc) {
            switch ($doc->getStatus()) {
                case 'approved':
                    $hasApproved = true;
                    break;
                case 'pending':
                    $hasPending = true;
                    break;
                case 'rejected':
                    $hasRejected = true;
                    break;
            }
        }
        
        if ($hasRejected) {
            return 'rejected';
        }
        
        if ($hasPending && !$hasApproved) {
            return 'pending';
        }
        
        if ($hasApproved) {
            return 'verified';
        }
        
        return 'missing';
    }
}
