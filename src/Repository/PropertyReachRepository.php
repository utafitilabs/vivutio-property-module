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
use Vivutio\Property\Entity\PropertyReach;

/**
 * @extends ServiceEntityRepository<PropertyReach>
 */
class PropertyReachRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropertyReach::class);
    }

    /** A person's chosen reach, by their uuid, or null where none was chosen. */
    public function findOneByPerson(string $person): ?PropertyReach
    {
        /** @var PropertyReach|null $reach */
        $reach = $this->createQueryBuilder('r')
            ->join('r.person', 'p')
            ->andWhere('p.uuid = :person')
            ->setParameter('person', $person)
            ->getQuery()
            ->getOneOrNullResult();

        return $reach;
    }
}
