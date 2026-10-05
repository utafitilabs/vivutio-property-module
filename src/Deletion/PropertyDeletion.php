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

namespace Vivutio\Property\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Contracts\Deletion\DeletionContributorInterface;
use Vivutio\Contracts\Deletion\DeletionLine;
use Vivutio\Contracts\Deletion\DeletionSubject;
use Vivutio\Property\Entity\Closure;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Entity\PropertyReach;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\Season;

/**
 * A property goes with everything under it, which the database removes with
 * it: room types and their rates, seasons and their periods, closures,
 * bookings. What only names it by kind and id is cleared here: people posted
 * there are posted nowhere, departments that sat there sit with the
 * organization, and a reach naming it keeps the rest, so it never widens.
 */
final readonly class PropertyDeletion implements DeletionContributorInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function supports(object $record): bool
    {
        return $record instanceof Property;
    }

    public function describe(object $record): DeletionSubject
    {
        \assert($record instanceof Property);

        return new DeletionSubject('property', (string) $record->getName(), (string) $record->getName());
    }

    public function whatGoes(object $record): array
    {
        $lines = [new DeletionLine(1, 'property', 'properties')];
        foreach ([[RoomType::class, 'room type', 'room types'], [Season::class, 'season', 'seasons'], [Closure::class, 'closure', 'closures'], [PropertyBooking::class, 'booking', 'bookings']] as [$class, $one, $many]) {
            $count = $this->entityManager->getRepository($class)->count(['property' => $record]);
            if ($count > 0) {
                $lines[] = new DeletionLine($count, $one, $many);
            }
        }

        return $lines;
    }

    public function whatStays(object $record): array
    {
        \assert($record instanceof Property);
        $lines = [];
        foreach ([
            [\count($this->posted($record)), 'person posted there, who is then posted nowhere', 'people posted there, who are then posted nowhere'],
            [\count($this->sitting($record)), 'department sitting there, which then sits with the organization', 'departments sitting there, which then sit with the organization'],
            [\count($this->reaching($record)), 'person whose permissions apply there, who then keeps the rest of their properties', 'people whose permissions apply there, who then keep the rest of their properties'],
        ] as [$count, $one, $many]) {
            if ($count > 0) {
                $lines[] = new DeletionLine($count, $one, $many);
            }
        }

        return $lines;
    }

    public function delete(object $record): void
    {
        \assert($record instanceof Property);
        foreach ($this->posted($record) as $person) {
            $person->setPosting(null, null);
        }
        foreach ($this->sitting($record) as $department) {
            $department->setPlace(null);
        }
        foreach ($this->reaching($record) as $reach) {
            $reach->setProperties(array_values(array_diff($reach->getProperties(), [$record->getPlaceId()])));
        }
        $this->entityManager->remove($record);
    }

    /**
     * @return list<User>
     */
    private function posted(Property $property): array
    {
        return $this->entityManager->getRepository(User::class)->findBy(['postedKind' => Property::PLACE_KIND, 'postedId' => $property->getPlaceId()]);
    }

    /**
     * @return list<Department>
     */
    private function sitting(Property $property): array
    {
        return $this->entityManager->getRepository(Department::class)->findBy(['placeKind' => Property::PLACE_KIND, 'placeId' => $property->getPlaceId()]);
    }

    /**
     * @return list<PropertyReach>
     */
    private function reaching(Property $property): array
    {
        return array_values(array_filter($this->entityManager->getRepository(PropertyReach::class)->findAll(), static fn (PropertyReach $reach): bool => \in_array($property->getPlaceId(), $reach->getProperties(), true)));
    }
}
