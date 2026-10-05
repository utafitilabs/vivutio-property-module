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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Vivutio\Bundle\IdentityBundle\Entity\Trait\TimestampableTrait;
use Vivutio\Bundle\IdentityBundle\Entity\Trait\UuidTrait;
use Vivutio\Property\Repository\ClosureRepository;

/**
 * Nights a property sells less than it has, with the reason: the whole
 * property shut, the long rains, or some units of one room type out of
 * service. From its first night to its last, both in it.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: ClosureRepository::class)]
#[ORM\Table(name: 'property_closure')]
#[ORM\HasLifecycleCallbacks]
class Closure
{
    use TimestampableTrait;
    use UuidTrait;

    public const int REASON_MAX_LENGTH = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    /** The room type it takes units of, or null for the whole property. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?RoomType $roomType = null;

    /** How many units of the room type it takes. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $units = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $starts;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $ends;

    #[ORM\Column(length: self::REASON_MAX_LENGTH)]
    private string $reason;

    public function __construct(Property $property, \DateTimeImmutable $starts, \DateTimeImmutable $ends, string $reason)
    {
        $this->property = $property;
        $this->starts = $starts;
        $this->ends = $ends;
        $this->reason = $reason;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProperty(): Property
    {
        return $this->property;
    }

    public function getRoomType(): ?RoomType
    {
        return $this->roomType;
    }

    public function getUnits(): ?int
    {
        return $this->units;
    }

    /** It takes these units of a room type, rather than the whole property. */
    public function setUnitsOf(RoomType $roomType, int $units): static
    {
        $this->roomType = $roomType;
        $this->units = $units;

        return $this;
    }

    public function getStarts(): \DateTimeImmutable
    {
        return $this->starts;
    }

    public function getEnds(): \DateTimeImmutable
    {
        return $this->ends;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /** How many units of a room type it takes on a night it covers. */
    public function takes(RoomType $room): int
    {
        if (null === $this->roomType) {
            return $room->getCount();
        }

        return $this->roomType->getId() === $room->getId() ? (int) $this->units : 0;
    }

    public function covers(\DateTimeImmutable $night): bool
    {
        return $this->starts <= $night && $this->ends >= $night;
    }
}
