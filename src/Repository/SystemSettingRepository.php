<?php

namespace App\Repository;

use App\Entity\SystemSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SystemSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemSetting::class);
    }

    public function findOrCreateSystemSetting(): SystemSetting
    {
        $setting = $this->findOneBy(['key' => 'system']);

        if (!$setting) {
            $setting = new SystemSetting();
            $setting->setKey('system');
            $setting->setData([]);
            $this->getEntityManager()->persist($setting);
            $this->getEntityManager()->flush();
        }

        return $setting;
    }
}