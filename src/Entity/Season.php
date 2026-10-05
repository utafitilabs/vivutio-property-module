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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Vivutio\Bundle\IdentityBundle\Entity\Trait\TimestampableTrait;
use Vivutio\Bundle\IdentityBundle\Entity\Trait\UuidTrait;
use Vivutio\Property\Enum\SeasonKindEnum;
use Vivutio\Property\Repository\SeasonRepository;

/**
 * A season of a property, High Season or Green Season, of a kind, and the
 * dated periods it runs. Its rates hang on its periods.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: SeasonRepository::class)]
#[ORM\Table(name: 'property_season')]
#[ORM\HasLifecycleCallbacks]
class Season
{
    use TimestampableTrait;
    use UuidTrait;

    public const int NAME_MAX_LENGTH = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name = '';

    #[ORM\Column(length: 16, enumType: SeasonKindEnum::class)]
    private SeasonKindEnum $kind = SeasonKindEnum::High;

    /** @var list<array{days: int, percent: int}>|null its own cancellation tiers, none for no charge, or null to follow its property's */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $cancellation = null;

    /** @var Collection<int, SeasonPeriod> */
    #[ORM\OneToMany(targetEntity: SeasonPeriod::class, mappedBy: 'season')]
    #[ORM\OrderBy(['starts' => 'ASC'])]
    private Collection $periods;

    public function __construct(Property $property)
    {
        $this->property = $property;
        $this->periods = new ArrayCollection();
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

    public function getKind(): SeasonKindEnum
    {
        return $this->kind;
    }

    public function setKind(SeasonKindEnum $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /**
     * @return list<array{days: int, percent: int}>|null
     */
    public function getCancellation(): ?array
    {
        return $this->cancellation;
    }

    /**
     * @param list<array{days: int, percent: int}>|null $cancellation
     */
    public function setCancellation(?array $cancellation): static
    {
        $this->cancellation = $cancellation;

        return $this;
    }

    /**
     * @return Collection<int, SeasonPeriod>
     */
    public function getPeriods(): Collection
    {
        return $this->periods;
    }
}
