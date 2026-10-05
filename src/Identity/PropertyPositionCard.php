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

namespace Vivutio\Property\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Vivutio\Bundle\IdentityBundle\Repository\UserRepository;
use Vivutio\Contracts\Identity\PositionCardFieldInterface;
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyReach;
use Vivutio\Property\Enum\ReachEnum;
use Vivutio\Property\Place\PropertyPlaces;
use Vivutio\Property\Repository\PropertyReachRepository;

/**
 * "Permissions apply at" on a person's Position card (ruled 2 October, design
 * C): the whole organization, where they are posted, or chosen properties,
 * saved with the card. "Where posted" is a property's, so it is not offered to
 * somebody posted at an office or nowhere.
 */
final readonly class PropertyPositionCard implements PositionCardFieldInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyReachRepository $reaches,
        private UserRepository $users,
        private PropertyPlaces $places,
    ) {
    }

    public function template(): string
    {
        return '@VivutioProperty/reach/_position_card.html.twig';
    }

    public function context(string $person, ?array $sent, ?PlaceInterface $postedAt): array
    {
        $stored = $this->reaches->findOneByPerson($person);
        $reach = null === $sent ? ($stored?->getReach() ?? ReachEnum::Organization) : (ReachEnum::tryFrom(self::text($sent, 'reach')) ?? ReachEnum::Organization);

        return [
            'reaches' => ReachEnum::cases(),
            'reach' => $reach,
            'chosen' => null === $sent ? ($stored?->getProperties() ?? []) : self::chosen($sent),
            'offered' => [...$this->places->places()],
            'posted_at_property' => null !== $postedAt && Property::PLACE_KIND === $postedAt->getPlaceKind(),
        ];
    }

    public function check(string $person, array $sent, ?PlaceInterface $postedAt): array
    {
        $reach = ReachEnum::tryFrom(self::text($sent, 'reach'));
        if (null === $reach) {
            return ['reach' => 'Choose where their permissions apply.'];
        }
        if (ReachEnum::Posted === $reach && (null === $postedAt || Property::PLACE_KIND !== $postedAt->getPlaceKind())) {
            return ['reach' => null === $postedAt
                ? 'They are posted nowhere, so "where posted" reaches nothing: post them at a property, or choose another.'
                : \sprintf('%s is an office, and "where posted" reaches a property: choose the whole organization or chosen properties.', $postedAt->getName())];
        }
        if (ReachEnum::Chosen === $reach) {
            $chosen = self::chosen($sent);
            if ([] === $chosen) {
                return ['reach' => 'Choose at least one property, or another reach.'];
            }
            $offered = array_map(static fn (PlaceInterface $place): string => $place->getPlaceId(), [...$this->places->places()]);
            if ([] !== array_diff($chosen, $offered)) {
                return ['reach' => 'Choose among the properties offered.'];
            }
        }

        return [];
    }

    public function save(string $person, array $sent, ?PlaceInterface $postedAt): void
    {
        $user = $this->users->findOneBy(['uuid' => $person]);
        $reach = ReachEnum::tryFrom(self::text($sent, 'reach'));
        if (null === $user || null === $reach) {
            return;
        }

        $stored = $this->reaches->findOneByPerson($person);
        if (null === $stored) {
            $stored = new PropertyReach($user);
            $this->entityManager->persist($stored);
        }
        $stored->setReach($reach)->setProperties(ReachEnum::Chosen === $reach ? self::chosen($sent) : []);
        $this->entityManager->flush();
    }

    /**
     * @return array{string, string}
     */
    public function summary(string $person): array
    {
        $stored = $this->reaches->findOneByPerson($person);
        $reach = $stored?->getReach() ?? ReachEnum::Organization;

        return ['Permissions apply at', match ($reach) {
            ReachEnum::Organization => $reach->label(),
            ReachEnum::Posted => $reach->label().' · '.($this->postedName($person) ?? 'posted nowhere'),
            ReachEnum::Chosen => $reach->label().' · '.implode(', ', array_map(fn (string $id): string => $this->places->find($id)?->getName() ?? 'a property no longer kept', $stored?->getProperties() ?? [])),
        }];
    }

    private function postedName(string $person): ?string
    {
        $user = $this->users->findOneBy(['uuid' => $person]);
        if (null === $user || Property::PLACE_KIND !== $user->getPostedKind() || null === $user->getPostedId()) {
            return null;
        }

        return $this->places->find($user->getPostedId())?->getName();
    }

    /**
     * @param array<string, mixed> $sent
     */
    private static function text(array $sent, string $name): string
    {
        return \is_string($sent[$name] ?? null) ? $sent[$name] : '';
    }

    /**
     * @param array<string, mixed> $sent
     *
     * @return list<string>
     */
    private static function chosen(array $sent): array
    {
        $chosen = $sent['reach_properties'] ?? [];

        return \is_array($chosen) ? array_values(array_unique(array_filter($chosen, \is_string(...)))) : [];
    }
}
