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

namespace Vivutio\Property\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Entity\PropertyBookingLine;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Enum\BookingStatusEnum;
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Exception\InvalidBookingException;
use Vivutio\Property\Exception\QuoteRefusedException;
use Vivutio\Property\Model\BookingDetails;
use Vivutio\Property\Model\CancellationCharge;
use Vivutio\Property\Model\Quote;
use Vivutio\Property\Repository\PropertyBookingRepository;
use Vivutio\Property\Repository\RoomTypeRepository;

/**
 * Bookings received at a property. A booking is made only at an open property,
 * for rooms free every night of the stay; each line is priced by the stay
 * service, and the total and each night's cancellation tiers are kept with it.
 * It is provisional, holding its rooms until a day, or confirmed; it is
 * cancelled at the charge of the tiers it was made under, and a hold is
 * cancelled free.
 */
final readonly class PropertyBookingService
{
    public const int MOST_LINES = 6;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private PropertyBookingRepository $bookings,
        private RoomTypeRepository $rooms,
        private RateQuoteService $quotes,
        private AvailabilityService $availability,
        private CancellationService $cancellation,
        private PartnerDirectoryInterface $partners,
    ) {
    }

    /**
     * @throws InvalidBookingException
     */
    public function record(Property $property, BookingDetails $details): PropertyBooking
    {
        if (PropertyStatusEnum::Open !== $property->getStatus()) {
            throw new InvalidBookingException('property', \sprintf('%s is %s: it is not taking new bookings.', $property->getName(), mb_strtolower($property->getStatus()->label())));
        }
        $today = $this->clock->now()->setTime(0, 0);

        $guest = self::text($details->guest, 'guest', 120, 'Say who it is for: the lead guest, or the party.');
        $arrival = self::date($details->arrival, 'arrival');
        if ($arrival < $today) {
            throw new InvalidBookingException('arrival', 'A booking arrives today or later.');
        }
        $nights = trim($details->nights);
        if (!ctype_digit($nights) || (int) $nights < 1 || (int) $nights > RateQuoteService::LONGEST_STAY) {
            throw new InvalidBookingException('nights', \sprintf('A stay is from 1 to %d nights.', RateQuoteService::LONGEST_STAY));
        }
        $nights = (int) $nights;

        $status = BookingStatusEnum::tryFrom($details->status);
        if (null === $status || BookingStatusEnum::Cancelled === $status) {
            throw new InvalidBookingException('status', 'A booking is made provisional or confirmed.');
        }
        $heldUntil = null;
        if (BookingStatusEnum::Provisional === $status) {
            $heldUntil = '' === trim($details->heldUntil) ? throw new InvalidBookingException('held_until', 'Say until when it holds its rooms.') : self::date($details->heldUntil, 'held_until');
            if ($heldUntil < $today || $heldUntil >= $arrival) {
                throw new InvalidBookingException('held_until', 'A hold runs from today to before the arrival.');
            }
        }

        $partner = null;
        if ('' !== trim($details->partner)) {
            $partner = $this->partners->find(trim($details->partner));
            if (null === $partner || !$partner->isActive()) {
                throw new InvalidBookingException('partner', 'Choose a partner you trade with now, or Direct.');
            }
        }

        $lines = $this->lines($property, $details, $arrival, $nights);

        $booking = (new PropertyBooking($property, $this->reference($property), $guest, $arrival, $nights, (string) $property->getCurrency()))
            ->setBookedBy(self::optional($details->bookedBy))
            ->setTheirReference(self::optional($details->theirReference))
            ->setNotes(self::optional($details->notes))
            ->setStatus($status)
            ->setHeldUntil($heldUntil);

        $total = 0;
        $kept = [];
        foreach ($lines as [$room, $count, $adults, $children, $infants, $board, $quote]) {
            $line = new PropertyBookingLine($booking, $room, $count, $adults, $children, $infants, $board, $quote->total * $count);
            $booking->getLines()->add($line);
            $this->entityManager->persist($line);
            $total += $line->getAmount();
            foreach ($this->cancellation->kept($quote) as $night) {
                $kept[$night['date']] ??= [...$night, 'total' => 0];
                $kept[$night['date']]['total'] += $night['total'] * $count;
            }
        }
        ksort($kept);
        $kept = array_values($kept);
        $booking->setGross($total);
        if (null !== $partner) {
            $booking->setPartnerTerms($partner->getPartnerId(), $partner->getDiscount(), $partner->getCreditDays());
            [$total, $kept] = self::discounted($total, $kept, $partner->getDiscount());
        }
        $booking->setTotal($total)->setPricedNights($kept);

        $this->entityManager->persist($booking);
        $this->entityManager->flush();

        return $booking;
    }

    /**
     * A hold is confirmed; one that has lapsed is confirmed only while its
     * rooms are still free.
     *
     * @throws InvalidBookingException
     */
    public function confirm(PropertyBooking $booking): void
    {
        if (BookingStatusEnum::Provisional !== $booking->getStatus()) {
            throw new InvalidBookingException('status', \sprintf('%s is %s: only a provisional booking is confirmed.', $booking->getReference(), mb_strtolower($booking->getStatus()->label())));
        }
        if ($booking->isLapsed($this->clock->now())) {
            foreach ($booking->getLines() as $i => $line) {
                $this->assertFree($line->getRoomType(), $line->getRooms(), $booking->getArrival(), $booking->getNights(), \sprintf('lines[%d][rooms]', $i));
            }
        }

        $booking->setStatus(BookingStatusEnum::Confirmed)->setHeldUntil(null);
        $this->entityManager->flush();
    }

    /**
     * Cancels it, at the charge of the tiers it was made under; a hold costs
     * nothing to cancel. Returns the charge, in cents.
     *
     * @throws InvalidBookingException
     */
    public function cancel(PropertyBooking $booking, string $reason): int
    {
        if (BookingStatusEnum::Cancelled === $booking->getStatus()) {
            throw new InvalidBookingException('status', \sprintf('%s is cancelled already.', $booking->getReference()));
        }
        $reason = self::text($reason, 'reason', 200, 'Say why it is cancelled.');
        $now = $this->clock->now();
        $charge = BookingStatusEnum::Provisional === $booking->getStatus() ? 0 : $this->chargeIf($booking, $now)->total;

        $booking->setCancelled($now, $reason, $charge);
        $this->entityManager->flush();

        return $charge;
    }

    /** What cancelling it on a day would cost. */
    public function chargeIf(PropertyBooking $booking, \DateTimeImmutable $on): CancellationCharge
    {
        return $this->cancellation->chargeOf($booking->getPricedNights(), $booking->getCurrency(), $on);
    }

    /**
     * The total and each night's cost after a discount: each night less its
     * share, the last night taking the cents rounding leaves, so the nights
     * add up to the total a cancellation is charged from.
     *
     * @param list<array{date: string, season: string, total: int, tiers: list<array{days: int, percent: int}>}> $nights
     *
     * @return array{int, list<array{date: string, season: string, total: int, tiers: list<array{days: int, percent: int}>}>}
     */
    private static function discounted(int $gross, array $nights, string $discount): array
    {
        $total = $gross - (int) round($gross * (float) $discount / 100);
        $left = $total;
        foreach ($nights as $i => $night) {
            $nights[$i]['total'] = array_key_last($nights) === $i ? $left : $night['total'] - (int) round($night['total'] * (float) $discount / 100);
            $left -= $nights[$i]['total'];
        }

        return [$total, $nights];
    }

    /**
     * Each line as typed, read, priced and checked against what is free.
     *
     * @return list<array{RoomType, int, int, int, int, BoardBasisEnum, Quote}>
     *
     * @throws InvalidBookingException
     */
    private function lines(Property $property, BookingDetails $details, \DateTimeImmutable $arrival, int $nights): array
    {
        $typed = array_values(array_filter($details->lines, static fn (array $line): bool => '' !== trim($line['room'])));
        if ([] === $typed) {
            throw new InvalidBookingException('lines', 'A booking has at least one room.');
        }
        if (\count($typed) > self::MOST_LINES) {
            throw new InvalidBookingException('lines', \sprintf('At most %d lines.', self::MOST_LINES));
        }

        $onSale = [];
        foreach ($this->rooms->findOnSaleByProperty($property) as $room) {
            $onSale[(string) $room->getUuid()] = $room;
        }

        $lines = [];
        $wanted = [];
        foreach ($typed as $i => $line) {
            $field = static fn (string $name): string => \sprintf('lines[%d][%s]', $i, $name);
            $room = $onSale[$line['room']] ?? throw new InvalidBookingException($field('room'), 'Choose one of the room types on sale.');
            $count = self::count($line['rooms'], $field('rooms'), 1, 'At least one room.');
            $adults = self::count($line['adults'], $field('adults'), 0, 'A whole number of adults.');
            $children = self::count($line['children'], $field('children'), 0, 'A whole number of children.');
            $infants = self::count($line['infants'], $field('infants'), 0, 'A whole number of infants.');
            $board = BoardBasisEnum::tryFrom($line['board']);
            if (null === $board || !\in_array($board->value, $property->getBoards(), true)) {
                throw new InvalidBookingException($field('board'), \sprintf('Choose a board basis %s sells.', $property->getName()));
            }

            try {
                $quote = $this->quotes->quote($room, $board, $arrival, $nights, $adults, $children, $infants);
            } catch (QuoteRefusedException $refusal) {
                $about = match (true) {
                    str_contains($refusal->getMessage(), 'adults at most'), str_contains($refusal->getMessage(), 'sleeps'), str_contains($refusal->getMessage(), 'at least one guest') => 'adults',
                    str_contains($refusal->getMessage(), 'at least one night') => 'nights',
                    default => 'room',
                };

                throw new InvalidBookingException('nights' === $about ? 'nights' : $field($about), $refusal->getMessage());
            }

            $wanted[(string) $room->getUuid()] = ($wanted[(string) $room->getUuid()] ?? 0) + $count;
            $this->assertFree($room, $wanted[(string) $room->getUuid()], $arrival, $nights, $field('rooms'));
            $lines[] = [$room, $count, $adults, $children, $infants, $board, $quote];
        }

        return $lines;
    }

    /**
     * @throws InvalidBookingException
     */
    private function assertFree(RoomType $room, int $count, \DateTimeImmutable $arrival, int $nights, string $field): void
    {
        for ($i = 0, $night = $arrival; $i < $nights; ++$i, $night = $night->modify('+1 day')) {
            $free = $this->availability->free($room, $night);
            if ($free < $count) {
                throw new InvalidBookingException($field, \sprintf('%s has %d free on %s.', $room->getName(), $free, $night->format('j M Y')));
            }
        }
    }

    /** "VLL-0001": the property's initials and its running number. */
    private function reference(Property $property): string
    {
        $initials = '';
        foreach (preg_split('/[^\p{L}]+/u', $property->getName(), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return \sprintf('%s-%04d', mb_substr('' === $initials ? 'PR' : $initials, 0, 4), $this->bookings->countByProperty($property) + 1);
    }

    /**
     * @throws InvalidBookingException
     */
    private static function text(string $typed, string $field, int $most, string $empty): string
    {
        $typed = trim($typed);
        if ('' === $typed) {
            throw new InvalidBookingException($field, $empty);
        }
        if (mb_strlen($typed) > $most) {
            throw new InvalidBookingException($field, \sprintf('At most %d characters.', $most));
        }

        return $typed;
    }

    private static function optional(string $typed): ?string
    {
        $typed = trim($typed);

        return '' === $typed ? null : mb_substr($typed, 0, 2000);
    }

    /**
     * @throws InvalidBookingException
     */
    private static function count(string $typed, string $field, int $least, string $said): int
    {
        $typed = trim($typed);
        if (!ctype_digit($typed) || (int) $typed < $least || (int) $typed > 500) {
            throw new InvalidBookingException($field, $said);
        }

        return (int) $typed;
    }

    /**
     * @throws InvalidBookingException
     */
    private static function date(string $typed, string $field): \DateTimeImmutable
    {
        $typed = trim($typed);
        $date = 1 === preg_match('{^\d{4}-\d{2}-\d{2}$}D', $typed) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $typed) : false;
        if (false === $date || $date->format('Y-m-d') !== $typed) {
            throw new InvalidBookingException($field, 'A date is a day of the calendar: 2026-10-30.');
        }

        return $date;
    }
}
