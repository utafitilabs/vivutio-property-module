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

namespace Vivutio\Property\Entity;

use Doctrine\ORM\Mapping as ORM;
use Vivutio\Bundle\IdentityBundle\Entity\Trait\TimestampableTrait;
use Vivutio\Bundle\IdentityBundle\Entity\Trait\UuidTrait;
use Vivutio\Property\Enum\UnitEnum;
use Vivutio\Property\Repository\PropertyRepository;

/**
 * A property: a camp, a lodge or a hotel the organization runs, where it is,
 * and what its guests sleep in.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: PropertyRepository::class)]
#[ORM\Table(name: 'property_property')]
#[ORM\HasLifecycleCallbacks]
class Property
{
    use TimestampableTrait;
    use UuidTrait;

    public const int NAME_MAX_LENGTH = 120;

    public const int LOCATION_MAX_LENGTH = 160;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name = '';

    /** Where it is, in the reader's words: "Central Serengeti, Tanzania". */
    #[ORM\Column(length: self::LOCATION_MAX_LENGTH)]
    private string $location = '';

    #[ORM\Column]
    private int $units = 0;

    #[ORM\Column(length: 16, enumType: UnitEnum::class)]
    private UnitEnum $unit = UnitEnum::Rooms;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getUnits(): int
    {
        return $this->units;
    }

    public function setUnits(int $units): static
    {
        $this->units = $units;

        return $this;
    }

    public function getUnit(): UnitEnum
    {
        return $this->unit;
    }

    public function setUnit(UnitEnum $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    /** "24 tents". */
    public function getSize(): string
    {
        return $this->units.' '.(1 === $this->units ? rtrim($this->unit->value, 's') : $this->unit->value);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
