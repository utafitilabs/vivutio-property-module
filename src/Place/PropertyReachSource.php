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

namespace Vivutio\Property\Place;

use Vivutio\Bundle\IdentityBundle\Repository\UserRepository;
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Place\ReachSourceInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Enum\ReachEnum;
use Vivutio\Property\Repository\PropertyReachRepository;

/**
 * Whether a person's reach covers a property: the whole organization covers
 * every one, where they are posted covers the one they are posted at now, and
 * chosen properties those chosen. It has no say over any other kind of place.
 */
final readonly class PropertyReachSource implements ReachSourceInterface
{
    public function __construct(
        private PropertyReachRepository $reaches,
        private UserRepository $users,
    ) {
    }

    public function covers(string $person, PlaceInterface $place): ?bool
    {
        if (Property::PLACE_KIND !== $place->getPlaceKind()) {
            return null;
        }

        $reach = $this->reaches->findOneByPerson($person);

        return match ($reach?->getReach() ?? ReachEnum::Organization) {
            ReachEnum::Organization => true,
            ReachEnum::Posted => $this->users->findOneBy(['uuid' => $person])?->isPostedAt($place) ?? false,
            ReachEnum::Chosen => \in_array($place->getPlaceId(), $reach->getProperties(), true),
        };
    }
}
