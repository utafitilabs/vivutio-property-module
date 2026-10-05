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
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Repository\RateRepository;

/**
 * What a night of a room type costs in a season period on a board basis, in
 * its property's currency: per person sharing, or per room.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: RateRepository::class)]
#[ORM\Table(name: 'property_rate')]
#[ORM\UniqueConstraint(columns: ['room_type_id', 'period_id', 'board'])]
#[ORM\HasLifecycleCallbacks]
class Rate
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RoomType $roomType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SeasonPeriod $period;

    #[ORM\Column(length: 16, enumType: BoardBasisEnum::class)]
    private BoardBasisEnum $board;

    /** The amount, to the cent: "290.00". */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount = '0.00';

    public function __construct(RoomType $roomType, SeasonPeriod $period, BoardBasisEnum $board)
    {
        $this->roomType = $roomType;
        $this->period = $period;
        $this->board = $board;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoomType(): RoomType
    {
        return $this->roomType;
    }

    public function getPeriod(): SeasonPeriod
    {
        return $this->period;
    }

    public function getBoard(): BoardBasisEnum
    {
        return $this->board;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }
}
