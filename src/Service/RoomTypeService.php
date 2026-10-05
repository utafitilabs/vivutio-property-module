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

use Doctrine\ORM\EntityManagerInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Enum\RoomFeatureEnum;
use Vivutio\Property\Exception\InvalidRoomTypeException;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Repository\RoomTypeRepository;

/**
 * A property's room types: named once in their property, each sleeping some
 * guests of whom some may be adults, so many of them, with what is in them.
 * An open property keeps at least one on sale.
 */
final readonly class RoomTypeService
{
    public const int MOST_GUESTS = 20;
    public const int MOST_OF_A_KIND = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoomTypeRepository $rooms,
    ) {
    }

    /**
     * @throws InvalidRoomTypeException
     */
    public function create(Property $property, RoomTypeDetails $details): RoomType
    {
        $room = new RoomType($property);
        $this->fill($room, $details);

        $this->entityManager->persist($room);
        $this->entityManager->flush();

        return $room;
    }

    /**
     * @throws InvalidRoomTypeException
     */
    public function change(RoomType $room, RoomTypeDetails $details): void
    {
        $this->fill($room, $details);
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidRoomTypeException
     */
    private function fill(RoomType $room, RoomTypeDetails $details): void
    {
        $name = trim($details->name);
        if ('' === $name) {
            throw new InvalidRoomTypeException('name', 'A room type is known by its name: it cannot be empty.');
        }
        if (mb_strlen($name) > RoomType::NAME_MAX_LENGTH) {
            throw new InvalidRoomTypeException('name', \sprintf('A name can be at most %d characters.', RoomType::NAME_MAX_LENGTH));
        }
        foreach ($this->rooms->findByProperty($room->getProperty()) as $other) {
            if ($other !== $room && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new InvalidRoomTypeException('name', \sprintf('%s already has a room type called %s.', $room->getProperty()->getName(), $other->getName()));
            }
        }

        $sleeps = $this->number($details->sleeps, 'sleeps', self::MOST_GUESTS, 'A room sleeps at least one guest');
        $adults = $this->number($details->adults, 'adults', self::MOST_GUESTS, 'At least one adult');
        if ($adults > $sleeps) {
            throw new InvalidRoomTypeException('adults', \sprintf('It sleeps %d, so it takes %d adults at most.', $sleeps, $sleeps));
        }
        $count = $this->number($details->count, 'count', self::MOST_OF_A_KIND, 'A property has at least one of a room type');

        $features = [];
        foreach ($details->features as $feature) {
            $features[] = (RoomFeatureEnum::tryFrom($feature) ?? throw new InvalidRoomTypeException('features', 'Choose among the features offered.'))->value;
        }

        if (!$details->onSale && !$room->isWithdrawn() && null !== $room->getId() && PropertyStatusEnum::Open === $room->getProperty()->getStatus()) {
            $others = array_filter($this->rooms->findOnSaleByProperty($room->getProperty()), static fn (RoomType $other): bool => $other !== $room);
            if ([] === $others) {
                throw new InvalidRoomTypeException('on_sale', \sprintf('%s is open and this is the last room type it sells: close the property first.', $room->getProperty()->getName()));
            }
        }

        $description = trim($details->description);
        $room->setName($name)->setSleeps($sleeps)->setAdults($adults)->setCount($count)
            ->setDescription('' === $description ? null : $description)
            ->setFeatures(array_values(array_unique($features)))
            ->setWithdrawn(!$details->onSale);
    }

    /**
     * @throws InvalidRoomTypeException
     */
    private function number(string $typed, string $field, int $most, string $least): int
    {
        $typed = trim($typed);
        if (!ctype_digit($typed) || (int) $typed < 1) {
            throw new InvalidRoomTypeException($field, $least.'.');
        }
        if ((int) $typed > $most) {
            throw new InvalidRoomTypeException($field, \sprintf('At most %d.', $most));
        }

        return (int) $typed;
    }
}
