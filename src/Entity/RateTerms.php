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
use Vivutio\Property\Repository\RateTermsRepository;

/**
 * A season period's terms, for a property priced per person sharing: what a
 * guest alone, a third adult, a child sharing, a child in a room of their own
 * and an infant each pay, as a share of the sharing rate in per cent. A term
 * left empty is not sold.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: RateTermsRepository::class)]
#[ORM\Table(name: 'property_rate_terms')]
#[ORM\HasLifecycleCallbacks]
class RateTerms
{
    use TimestampableTrait;

    public const array TERMS = ['single', 'third_adult', 'child_sharing', 'child_own_room', 'infant'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private SeasonPeriod $period;

    /** @var array<string, int> by term, those set */
    #[ORM\Column(type: Types::JSON)]
    private array $shares = [];

    public function __construct(SeasonPeriod $period)
    {
        $this->period = $period;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriod(): SeasonPeriod
    {
        return $this->period;
    }

    /** The share a term pays, in per cent, or null where it is not sold. */
    public function share(string $term): ?int
    {
        return $this->shares[$term] ?? null;
    }

    /**
     * @return array<string, int>
     */
    public function getShares(): array
    {
        return $this->shares;
    }

    /**
     * @param array<string, int> $shares
     */
    public function setShares(array $shares): static
    {
        $this->shares = $shares;

        return $this;
    }
}
