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
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Stay\StayInterface;
use Vivutio\Property\Enum\BookingStatusEnum;
use Vivutio\Property\Repository\PropertyBookingRepository;

/**
 * A booking received at a property: who it is for and who made it, from which
 * night for how many, its lines of rooms, what it cost when it was made, and
 * the cancellation terms in force then, night by night, so a later change of
 * rates or terms never alters it. It belongs to its property, so a permission
 * asked about it is asked about there.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: PropertyBookingRepository::class)]
#[ORM\Table(name: 'property_booking')]
#[ORM\HasLifecycleCallbacks]
class PropertyBooking implements StayInterface
{
    use TimestampableTrait;
    use UuidTrait;
    /** The kind of stay a booking is, wherever a front desk keeps it. */
    public const string STAY_KIND = 'property_booking';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    /** What everybody calls it: "VLL-0001". */
    #[ORM\Column(length: 16, unique: true)]
    private string $reference;

    /** Who it is for: the lead guest, or the party's name. */
    #[ORM\Column(length: 120)]
    private string $guest;

    /** Who made it: an operator, an agent, or the guest directly. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $bookedBy = null;

    /** Their own reference for it. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $theirReference = null;

    /** The first night. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $arrival;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $nights;

    #[ORM\Column(length: 16, enumType: BookingStatusEnum::class)]
    private BookingStatusEnum $status = BookingStatusEnum::Provisional;

    /** For a provisional booking, the last day it holds its rooms. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $heldUntil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** What it costs, in cents of its currency, after the partner's discount when one made it. */
    #[ORM\Column]
    private int $total = 0;

    /** What it cost at the property's rates when it was made, before any discount, in cents. */
    #[ORM\Column]
    private int $gross = 0;

    /** The partner that made it, by the id the core's partners are known by; null when it is direct. */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $partnerId = null;

    /** The discount it was made at, a share to the cent. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $discount = '0.00';

    /** The days to pay it was made with. */
    #[ORM\Column]
    private int $creditDays = 0;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** @var list<array{date: string, season: string, total: int, tiers: list<array{days: int, percent: int}>}> each night's cost and the cancellation tiers in force for it when it was made */
    #[ORM\Column(type: Types::JSON)]
    private array $pricedNights = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $cancellationReason = null;

    /** What cancelling it cost, in cents. */
    #[ORM\Column(nullable: true)]
    private ?int $cancellationCharge = null;

    /** @var Collection<int, PropertyBookingLine> */
    #[ORM\OneToMany(targetEntity: PropertyBookingLine::class, mappedBy: 'booking')]
    private Collection $lines;

    public function __construct(Property $property, string $reference, string $guest, \DateTimeImmutable $arrival, int $nights, string $currency)
    {
        $this->property = $property;
        $this->reference = $reference;
        $this->guest = $guest;
        $this->arrival = $arrival;
        $this->nights = $nights;
        $this->currency = $currency;
        $this->lines = new ArrayCollection();
    }

    public function placedAt(): PlaceInterface
    {
        return $this->property;
    }

    public function getStayKind(): string
    {
        return self::STAY_KIND;
    }

    public function getStayId(): string
    {
        return (string) $this->getUuid();
    }

    public function getUnits(): array
    {
        $units = [];
        foreach ($this->lines as $line) {
            $type = (string) $line->getRoomType()->getUuid();
            $units[$type] ??= ['type' => $type, 'name' => $line->getRoomType()->getName(), 'count' => 0];
            $units[$type]['count'] += $line->getRooms();
        }

        return array_values($units);
    }

    /** A confirmed booking is expected at the desk; a hold or a cancelled one is not. */
    public function isExpected(): bool
    {
        return BookingStatusEnum::Confirmed === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProperty(): Property
    {
        return $this->property;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getGuest(): string
    {
        return $this->guest;
    }

    public function getBookedBy(): ?string
    {
        return $this->bookedBy;
    }

    public function setBookedBy(?string $bookedBy): static
    {
        $this->bookedBy = $bookedBy;

        return $this;
    }

    public function getTheirReference(): ?string
    {
        return $this->theirReference;
    }

    public function setTheirReference(?string $theirReference): static
    {
        $this->theirReference = $theirReference;

        return $this;
    }

    public function getArrival(): \DateTimeImmutable
    {
        return $this->arrival;
    }

    public function getNights(): int
    {
        return $this->nights;
    }

    /** The morning they leave: the night after the last is not slept. */
    public function getDeparture(): \DateTimeImmutable
    {
        return $this->arrival->modify(\sprintf('+%d days', $this->nights));
    }

    public function getStatus(): BookingStatusEnum
    {
        return $this->status;
    }

    public function setStatus(BookingStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getHeldUntil(): ?\DateTimeImmutable
    {
        return $this->heldUntil;
    }

    public function setHeldUntil(?\DateTimeImmutable $heldUntil): static
    {
        $this->heldUntil = $heldUntil;

        return $this;
    }

    /** A provisional booking whose hold has run out. */
    public function isLapsed(\DateTimeImmutable $now): bool
    {
        return BookingStatusEnum::Provisional === $this->status && null !== $this->heldUntil && $this->heldUntil < $now->setTime(0, 0);
    }

    /** Whether it takes its rooms off the calendar now: confirmed, or held and not lapsed. */
    public function holdsRooms(\DateTimeImmutable $now): bool
    {
        return BookingStatusEnum::Confirmed === $this->status || (BookingStatusEnum::Provisional === $this->status && !$this->isLapsed($now));
    }

    /** Whether it sleeps a night. */
    public function covers(\DateTimeImmutable $night): bool
    {
        return $this->arrival <= $night && $this->getDeparture() > $night;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getGross(): int
    {
        return $this->gross;
    }

    public function setGross(int $gross): static
    {
        $this->gross = $gross;

        return $this;
    }

    public function getPartnerId(): ?string
    {
        return $this->partnerId;
    }

    /**
     * The partner that made it and the terms it was made at, kept as they
     * were: a partner's terms changing later changes no booking.
     */
    public function setPartnerTerms(?string $partnerId, string $discount, int $creditDays): static
    {
        $this->partnerId = $partnerId;
        $this->discount = $discount;
        $this->creditDays = $creditDays;

        return $this;
    }

    public function getDiscount(): string
    {
        return $this->discount;
    }

    public function getCreditDays(): int
    {
        return $this->creditDays;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @return list<array{date: string, season: string, total: int, tiers: list<array{days: int, percent: int}>}>
     */
    public function getPricedNights(): array
    {
        return $this->pricedNights;
    }

    /**
     * @param list<array{date: string, season: string, total: int, tiers: list<array{days: int, percent: int}>}> $pricedNights
     */
    public function setPricedNights(array $pricedNights): static
    {
        $this->pricedNights = $pricedNights;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function getCancellationCharge(): ?int
    {
        return $this->cancellationCharge;
    }

    public function setCancelled(\DateTimeImmutable $at, string $reason, int $charge): static
    {
        $this->status = BookingStatusEnum::Cancelled;
        $this->cancelledAt = $at;
        $this->cancellationReason = $reason;
        $this->cancellationCharge = $charge;

        return $this;
    }

    /**
     * @return Collection<int, PropertyBookingLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }
}
