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
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Enum\BookingStatusEnum;

/**
 * @extends ServiceEntityRepository<PropertyBooking>
 */
class PropertyBookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropertyBooking::class);
    }

    /**
     * A property's bookings, the soonest arrival first.
     *
     * @return list<PropertyBooking>
     */
    public function findByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['arrival' => 'ASC', 'reference' => 'ASC']);
    }

    /**
     * A property's bookings that may hold rooms, provisional or confirmed,
     * sleeping any night from the first to the last.
     *
     * @return list<PropertyBooking>
     */
    public function findHoldingBetween(Property $property, \DateTimeImmutable $first, \DateTimeImmutable $last): array
    {
        /** @var list<PropertyBooking> $bookings */
        $bookings = $this->createQueryBuilder('b')
            ->andWhere('b.property = :property')
            ->andWhere('b.status IN (:holding)')
            ->andWhere('b.arrival <= :last')
            ->setParameter('property', $property)
            ->setParameter('holding', [BookingStatusEnum::Provisional, BookingStatusEnum::Confirmed])
            ->setParameter('last', $last, 'date_immutable')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($bookings, static fn (PropertyBooking $booking): bool => $booking->getDeparture() > $first));
    }

    /** How many bookings a property has had, so the next is numbered after them. */
    public function countByProperty(Property $property): int
    {
        return $this->count(['property' => $property]);
    }
}
