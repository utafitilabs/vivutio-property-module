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
use Vivutio\Property\Repository\RoomTypeRepository;

/**
 * A kind of room a property sells, and how many of them it has: a Tented
 * Room, twenty of them, each sleeping two. A room type taken off sale is
 * withdrawn, never deleted, so what was sold of it keeps its name.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: RoomTypeRepository::class)]
#[ORM\Table(name: 'property_room_type')]
#[ORM\HasLifecycleCallbacks]
class RoomType
{
    use TimestampableTrait;
    use UuidTrait;

    public const int NAME_MAX_LENGTH = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** How many guests one of them sleeps, children included. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $sleeps = 1;

    /** How many of those may be adults: a family tent sleeps four, two of them adults. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $adults = 1;

    /** How many of them the property has. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $count = 1;

    /** @var list<string> each a RoomFeatureEnum value */
    #[ORM\Column(type: Types::JSON)]
    private array $features = [];

    #[ORM\Column]
    private bool $withdrawn = false;

    public function __construct(Property $property)
    {
        $this->property = $property;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProperty(): Property
    {
        return $this->property;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSleeps(): int
    {
        return $this->sleeps;
    }

    public function setSleeps(int $sleeps): static
    {
        $this->sleeps = $sleeps;

        return $this;
    }

    public function getAdults(): int
    {
        return $this->adults;
    }

    public function setAdults(int $adults): static
    {
        $this->adults = $adults;

        return $this;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): static
    {
        $this->count = $count;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * @param list<string> $features
     */
    public function setFeatures(array $features): static
    {
        $this->features = $features;

        return $this;
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn;
    }

    public function setWithdrawn(bool $withdrawn): static
    {
        $this->withdrawn = $withdrawn;

        return $this;
    }
}
