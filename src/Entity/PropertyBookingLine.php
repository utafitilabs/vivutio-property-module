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
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Repository\PropertyBookingLineRepository;

/**
 * One line of a booking: so many rooms of a room type, the party in each, on
 * a board basis, and what they cost for the whole stay when it was made.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: PropertyBookingLineRepository::class)]
#[ORM\Table(name: 'property_booking_line')]
class PropertyBookingLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PropertyBooking $booking;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private RoomType $roomType;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $rooms;

    /** The party in each room. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $adults;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $children;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $infants;

    #[ORM\Column(length: 16, enumType: BoardBasisEnum::class)]
    private BoardBasisEnum $board;

    /** What the line cost for the whole stay, every room, in cents. */
    #[ORM\Column]
    private int $amount;

    public function __construct(PropertyBooking $booking, RoomType $roomType, int $rooms, int $adults, int $children, int $infants, BoardBasisEnum $board, int $amount)
    {
        $this->booking = $booking;
        $this->roomType = $roomType;
        $this->rooms = $rooms;
        $this->adults = $adults;
        $this->children = $children;
        $this->infants = $infants;
        $this->board = $board;
        $this->amount = $amount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): PropertyBooking
    {
        return $this->booking;
    }

    public function getRoomType(): RoomType
    {
        return $this->roomType;
    }

    public function getRooms(): int
    {
        return $this->rooms;
    }

    public function getAdults(): int
    {
        return $this->adults;
    }

    public function getChildren(): int
    {
        return $this->children;
    }

    public function getInfants(): int
    {
        return $this->infants;
    }

    public function getBoard(): BoardBasisEnum
    {
        return $this->board;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }
}
