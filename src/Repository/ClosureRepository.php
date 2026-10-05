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
use Vivutio\Property\Entity\Closure;
use Vivutio\Property\Entity\Property;

/**
 * @extends ServiceEntityRepository<Closure>
 */
class ClosureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Closure::class);
    }

    /**
     * A property's closures that cover any night from the first to the last,
     * the earliest first.
     *
     * @return list<Closure>
     */
    public function findByPropertyBetween(Property $property, \DateTimeImmutable $first, \DateTimeImmutable $last): array
    {
        /** @var list<Closure> $closures */
        $closures = $this->createQueryBuilder('c')
            ->andWhere('c.property = :property')
            ->andWhere('c.starts <= :last')
            ->andWhere('c.ends >= :first')
            ->setParameter('property', $property)
            ->setParameter('first', $first, 'date_immutable')
            ->setParameter('last', $last, 'date_immutable')
            ->orderBy('c.starts', 'ASC')
            ->getQuery()
            ->getResult();

        return $closures;
    }
}
