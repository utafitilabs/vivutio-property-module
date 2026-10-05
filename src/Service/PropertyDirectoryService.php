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

namespace Vivutio\Property\Service;

use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Repository\DepartmentRepository;
use Vivutio\Bundle\IdentityBundle\Repository\UserRepository;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Repository\RoomTypeRepository;

/**
 * What the register and a property's page read beside the property itself:
 * who is posted there and which departments sit there, both the core's
 * records, and how many units it sells and how many guests they sleep.
 */
final readonly class PropertyDirectoryService
{
    public function __construct(
        private UserRepository $users,
        private DepartmentRepository $departments,
        private RoomTypeRepository $rooms,
    ) {
    }

    /**
     * @return list<User> by name
     */
    public function postedAt(Property $property): array
    {
        return $this->users->findBy(['postedKind' => Property::PLACE_KIND, 'postedId' => $property->getPlaceId()], ['firstName' => 'ASC', 'lastName' => 'ASC']);
    }

    /**
     * @return array<string, int> how many are posted at each property, by its uuid
     */
    public function postedCounts(): array
    {
        $counts = [];
        foreach ($this->users->findBy(['postedKind' => Property::PLACE_KIND]) as $user) {
            $id = (string) $user->getPostedId();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<Department> by name
     */
    public function departmentsAt(Property $property): array
    {
        return $this->departments->findBy(['placeKind' => Property::PLACE_KIND, 'placeId' => $property->getPlaceId()], ['name' => 'ASC']);
    }

    /**
     * The units on sale and the guests they sleep: "24 units, sleeping 56".
     *
     * @return array{units: int, sleeps: int}
     */
    public function size(Property $property): array
    {
        $size = ['units' => 0, 'sleeps' => 0];
        foreach ($this->rooms->findOnSaleByProperty($property) as $room) {
            $size['units'] += $room->getCount();
            $size['sleeps'] += $room->getCount() * $room->getSleeps();
        }

        return $size;
    }

    /**
     * @return array<string, int> the units each property has on sale, by its uuid
     */
    public function unitCounts(): array
    {
        $counts = [];
        foreach ($this->rooms->findOnSale() as $room) {
            $id = $room->getProperty()->getPlaceId();
            $counts[$id] = ($counts[$id] ?? 0) + $room->getCount();
        }

        return $counts;
    }

    /**
     * The figures every tab of a property shows in its band.
     *
     * @return array{units: int, sleeps: int, posted: int, departments: int}
     */
    public function band(Property $property): array
    {
        return [
            ...$this->size($property),
            'posted' => \count($this->postedAt($property)),
            'departments' => \count($this->departmentsAt($property)),
        ];
    }
}
