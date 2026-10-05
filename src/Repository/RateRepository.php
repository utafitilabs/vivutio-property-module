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
use Vivutio\Property\Entity\Rate;

/**
 * @extends ServiceEntityRepository<Rate>
 */
class RateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rate::class);
    }

    /**
     * Every rate of a property.
     *
     * @return list<Rate>
     */
    public function findByProperty(Property $property): array
    {
        /** @var list<Rate> $rates */
        $rates = $this->createQueryBuilder('r')
            ->join('r.roomType', 't')
            ->andWhere('t.property = :property')
            ->setParameter('property', $property)
            ->getQuery()
            ->getResult();

        return $rates;
    }
}
