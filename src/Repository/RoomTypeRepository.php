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
use Vivutio\Property\Entity\RoomType;

/**
 * @extends ServiceEntityRepository<RoomType>
 */
class RoomTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomType::class);
    }

    /**
     * A property's room types, smallest first.
     *
     * @return list<RoomType>
     */
    public function findByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['sleeps' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * @return list<RoomType>
     */
    public function findOnSaleByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property, 'withdrawn' => false], ['sleeps' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * Every room type on sale, of every property.
     *
     * @return list<RoomType>
     */
    public function findOnSale(): array
    {
        return $this->findBy(['withdrawn' => false]);
    }
}
