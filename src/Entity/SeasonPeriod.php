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
use Vivutio\Property\Repository\SeasonPeriodRepository;

/**
 * When a season runs: its first night and its last, both in it. No night of
 * a property is in two periods.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: SeasonPeriodRepository::class)]
#[ORM\Table(name: 'property_season_period')]
#[ORM\HasLifecycleCallbacks]
class SeasonPeriod
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne(inversedBy: 'periods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Season $season;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $starts;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $ends;

    public function __construct(Season $season, \DateTimeImmutable $starts, \DateTimeImmutable $ends)
    {
        $this->season = $season;
        $this->starts = $starts;
        $this->ends = $ends;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    /** Its first night. */
    public function getStarts(): \DateTimeImmutable
    {
        return $this->starts;
    }

    /** Its last night. */
    public function getEnds(): \DateTimeImmutable
    {
        return $this->ends;
    }
}
