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

namespace Vivutio\Property\Stay;

use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Stay\StayInterface;
use Vivutio\Contracts\Stay\StaySourceInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Repository\PropertyBookingRepository;
use Vivutio\Property\Repository\PropertyRepository;

/**
 * A property's bookings as stays, for a front desk to work from.
 */
final readonly class PropertyStays implements StaySourceInterface
{
    public function __construct(
        private PropertyRepository $properties,
        private PropertyBookingRepository $bookings,
    ) {
    }

    public function kind(): string
    {
        return PropertyBooking::STAY_KIND;
    }

    public function at(PlaceInterface $place, \DateTimeImmutable $first, \DateTimeImmutable $last): iterable
    {
        if (Property::PLACE_KIND !== $place->getPlaceKind()) {
            return [];
        }
        $property = $this->properties->findOneBy(['uuid' => $place->getPlaceId()]);
        if (null === $property) {
            return [];
        }

        return array_values(array_filter($this->bookings->findByProperty($property), static fn (PropertyBooking $booking): bool => $booking->getArrival() <= $last && $booking->getDeparture() >= $first));
    }

    public function find(string $id): ?StayInterface
    {
        return $this->bookings->findOneBy(['uuid' => $id]);
    }
}
