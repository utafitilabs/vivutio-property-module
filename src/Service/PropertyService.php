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
use Vivutio\Property\Enum\UnitEnum;
use Vivutio\Property\Exception\InvalidPropertyException;
use Vivutio\Property\Repository\PropertyRepository;

/**
 * Properties: named once, each somewhere, with what its guests sleep in.
 */
final readonly class PropertyService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyRepository $properties,
    ) {
    }

    /**
     * @throws InvalidPropertyException
     */
    public function create(string $name, string $location, int $units, string $unit): Property
    {
        $property = new Property();
        $this->fill($property, $name, $location, $units, $unit);

        $this->entityManager->persist($property);
        $this->entityManager->flush();

        return $property;
    }

    /**
     * @throws InvalidPropertyException
     */
    public function change(Property $property, string $name, string $location, int $units, string $unit): void
    {
        $this->fill($property, $name, $location, $units, $unit);
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidPropertyException
     */
    private function fill(Property $property, string $name, string $location, int $units, string $unit): void
    {
        $name = trim($name);
        $location = trim($location);

        if ('' === $name) {
            throw new InvalidPropertyException('name', 'A property is known by its name: it cannot be empty.');
        }
        if (mb_strlen($name) > Property::NAME_MAX_LENGTH) {
            throw new InvalidPropertyException('name', \sprintf('A name can be at most %d characters.', Property::NAME_MAX_LENGTH));
        }
        if ('' === $location) {
            throw new InvalidPropertyException('location', 'Say where it is.');
        }
        if (mb_strlen($location) > Property::LOCATION_MAX_LENGTH) {
            throw new InvalidPropertyException('location', \sprintf('A location can be at most %d characters.', Property::LOCATION_MAX_LENGTH));
        }
        if ($units < 1 || $units > 10000) {
            throw new InvalidPropertyException('units', 'A property has at least one unit.');
        }
        $kind = UnitEnum::tryFrom($unit);
        if (null === $kind) {
            throw new InvalidPropertyException('unit', 'Choose what its guests sleep in.');
        }

        foreach ($this->properties->findAll() as $other) {
            if ($other !== $property && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new InvalidPropertyException('name', \sprintf('There is already a property called %s.', $other->getName()));
            }
        }

        $property->setName($name)->setLocation($location)->setUnits($units)->setUnit($kind);
    }
}
