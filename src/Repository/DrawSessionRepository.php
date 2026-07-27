<?php

namespace App\Repository;

use App\Entity\DrawSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

class DrawSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DrawSession::class);
    }

    public function findByPublicId(string $publicId): ?DrawSession
    {
        return $this->findOneBy(['publicId' => $publicId]);
    }

    public function findByPublicIdForUpdate(string $publicId): ?DrawSession
    {
        $query = $this->createQueryBuilder('session')
            ->andWhere('session.publicId = :publicId')
            ->setParameter('publicId', $publicId)
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        return $query->getOneOrNullResult();
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('session')
            ->delete()
            ->andWhere('session.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
