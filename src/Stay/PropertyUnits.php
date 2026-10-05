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
use Vivutio\Contracts\Stay\UnitSourceInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Repository\PropertyRepository;
use Vivutio\Property\Repository\RoomTypeRepository;

/**
 * A property's room types on sale as the units a front desk puts guests in.
 */
final readonly class PropertyUnits implements UnitSourceInterface
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomTypeRepository $rooms,
    ) {
    }

    public function units(PlaceInterface $place): ?array
    {
        if (Property::PLACE_KIND !== $place->getPlaceKind()) {
            return null;
        }
        $property = $this->properties->findOneBy(['uuid' => $place->getPlaceId()]);
        if (null === $property) {
            return [];
        }

        $units = [];
        foreach ($this->rooms->findOnSaleByProperty($property) as $room) {
            $units[] = ['type' => (string) $room->getUuid(), 'name' => $room->getName(), 'count' => $room->getCount()];
        }

        return $units;
    }
}
