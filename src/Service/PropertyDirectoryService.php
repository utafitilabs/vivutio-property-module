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

/**
 * Who is posted at each property and which departments sit there, as the
 * register and a property's page read them; both are the core's records.
 */
final readonly class PropertyDirectoryService
{
    public function __construct(
        private UserRepository $users,
        private DepartmentRepository $departments,
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
}
