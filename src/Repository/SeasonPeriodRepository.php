<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio property module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\Property\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\SeasonPeriod;

/**
 * @extends ServiceEntityRepository<SeasonPeriod>
 */
class SeasonPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonPeriod::class);
    }

    /**
     * Every period of every season of a property, the earliest first.
     *
     * @return list<SeasonPeriod>
     */
    public function findByProperty(Property $property): array
    {
        /** @var list<SeasonPeriod> $periods */
        $periods = $this->createQueryBuilder('p')
            ->join('p.season', 's')
            ->andWhere('s.property = :property')
            ->setParameter('property', $property)
            ->orderBy('p.starts', 'ASC')
            ->getQuery()
            ->getResult();

        return $periods;
    }
}
