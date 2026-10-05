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
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Property\Enum\PricingEnum;
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Enum\PropertyTypeEnum;
use Vivutio\Property\Repository\PropertyRepository;

/**
 * A property: a camp, a lodge or a hotel the organization runs. What it is
 * and where, how it is reached, its house rules, and whether it takes
 * bookings; its rooms, seasons and rates are its own records. It is a place
 * people are posted at.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: PropertyRepository::class)]
#[ORM\Table(name: 'property_property')]
#[ORM\HasLifecycleCallbacks]
class Property implements PlaceInterface
{
    use TimestampableTrait;
    use UuidTrait;
    /** The kind of place a property is, wherever a posting keeps it. */
    public const string PLACE_KIND = 'property';

    public const int NAME_MAX_LENGTH = 120;

    public const int LOCATION_MAX_LENGTH = 160;
    public const int SUMMARY_MAX_LENGTH = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name = '';

    /** Where it is, in the reader's words: "Central Serengeti, Tanzania". */
    #[ORM\Column(length: self::LOCATION_MAX_LENGTH)]
    private string $location = '';

    #[ORM\Column(length: 16, enumType: PropertyTypeEnum::class)]
    private PropertyTypeEnum $type = PropertyTypeEnum::Lodge;

    #[ORM\Column(length: 16, enumType: PropertyStatusEnum::class)]
    private PropertyStatusEnum $status = PropertyStatusEnum::Draft;

    /** Where it is on a map, in degrees; both or neither. */
    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $longitude = null;

    /** Its star grading, one to five, or null where it has none. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $grading = null;

    /** The one line read first, in a list or a quote. */
    #[ORM\Column(length: self::SUMMARY_MAX_LENGTH, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** How a guest or a partner reaches the property itself. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    /** The time a room is ready from, and the time it is left by, in the property's own clock. */
    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkInFrom = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkOutBy = null;

    /** Who counts as an infant and who as a child, by age in years; both or neither. Older guests are adults. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $infantsUpTo = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $childrenUpTo = null;

    /** The one currency its rates are in, ISO 4217: USD. Null until its rate sheet is set up. */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(length: 16, enumType: PricingEnum::class)]
    private PricingEnum $pricing = PricingEnum::PerPerson;

    /** @var list<array{days: int, percent: int}> its cancellation tiers, the most days first; none, and cancelling is free */
    #[ORM\Column(type: Types::JSON)]
    private array $cancellation = [];

    /** @var list<string> the board bases it sells, each a BoardBasisEnum value */
    #[ORM\Column(type: Types::JSON)]
    private array $boards = [];

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

    public function getType(): PropertyTypeEnum
    {
        return $this->type;
    }

    public function setType(PropertyTypeEnum $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): PropertyStatusEnum
    {
        return $this->status;
    }

    public function setStatus(PropertyStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getGrading(): ?int
    {
        return $this->grading;
    }

    public function setGrading(?int $grading): static
    {
        $this->grading = $grading;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getCheckInFrom(): ?\DateTimeImmutable
    {
        return $this->checkInFrom;
    }

    public function setCheckInFrom(?\DateTimeImmutable $checkInFrom): static
    {
        $this->checkInFrom = $checkInFrom;

        return $this;
    }

    public function getCheckOutBy(): ?\DateTimeImmutable
    {
        return $this->checkOutBy;
    }

    public function setCheckOutBy(?\DateTimeImmutable $checkOutBy): static
    {
        $this->checkOutBy = $checkOutBy;

        return $this;
    }

    public function getInfantsUpTo(): ?int
    {
        return $this->infantsUpTo;
    }

    public function setInfantsUpTo(?int $infantsUpTo): static
    {
        $this->infantsUpTo = $infantsUpTo;

        return $this;
    }

    public function getChildrenUpTo(): ?int
    {
        return $this->childrenUpTo;
    }

    public function setChildrenUpTo(?int $childrenUpTo): static
    {
        $this->childrenUpTo = $childrenUpTo;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getPricing(): PricingEnum
    {
        return $this->pricing;
    }

    public function setPricing(PricingEnum $pricing): static
    {
        $this->pricing = $pricing;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getBoards(): array
    {
        return $this->boards;
    }

    /**
     * @param list<string> $boards
     */
    public function setBoards(array $boards): static
    {
        $this->boards = $boards;

        return $this;
    }

    /**
     * @return list<array{days: int, percent: int}>
     */
    public function getCancellation(): array
    {
        return $this->cancellation;
    }

    /**
     * @param list<array{days: int, percent: int}> $cancellation
     */
    public function setCancellation(array $cancellation): static
    {
        $this->cancellation = $cancellation;

        return $this;
    }

    public function getPlaceKind(): string
    {
        return self::PLACE_KIND;
    }

    public function getPlaceId(): string
    {
        return (string) $this->getUuid();
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
