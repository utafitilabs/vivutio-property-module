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
use Vivutio\Property\Entity\Closure;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Exception\InvalidClosureException;
use Vivutio\Property\Model\AvailabilityMonth;
use Vivutio\Property\Model\AvailabilityNight;
use Vivutio\Property\Repository\ClosureRepository;
use Vivutio\Property\Repository\PropertyBookingRepository;
use Vivutio\Property\Repository\RoomTypeRepository;

/**
 * What a property has free, night by night: each room type's count, less
 * what its closures take and what its bookings hold. Only the closures and the
 * bookings are kept; every night is worked out from them, so nothing is copied
 * that could drift.
 */
final readonly class AvailabilityService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClosureRepository $closures,
        private RoomTypeRepository $rooms,
        private PropertyBookingRepository $bookings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A closure as the form sends it: the whole property when no room type is
     * chosen, else so many units of one.
     *
     * @throws InvalidClosureException
     */
    public function close(Property $property, string $room, string $units, string $starts, string $ends, string $reason): Closure
    {
        $first = self::date($starts, 'starts');
        $last = self::date($ends, 'ends');
        if ($last < $first) {
            throw new InvalidClosureException('ends', 'A closure ends after it starts, or on the same night.');
        }
        if ($last >= $first->modify('+1 year')) {
            throw new InvalidClosureException('ends', 'A closure runs for a year at most; add another for the next.');
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new InvalidClosureException('reason', 'Say why: Long rains, Canvas repairs.');
        }
        if (mb_strlen($reason) > Closure::REASON_MAX_LENGTH) {
            throw new InvalidClosureException('reason', \sprintf('A reason can be at most %d characters.', Closure::REASON_MAX_LENGTH));
        }

        $closure = new Closure($property, $first, $last, $reason);
        if ('' === $room) {
            if ('' !== trim($units)) {
                throw new InvalidClosureException('units', 'The whole property is closed, every unit; choose a room type to take some of its units.');
            }
        } else {
            $taken = null;
            foreach ($this->rooms->findByProperty($property) as $candidate) {
                if ((string) $candidate->getUuid() === $room) {
                    $taken = $candidate;
                }
            }
            if (null === $taken) {
                throw new InvalidClosureException('room', 'Choose one of its room types, or the whole property.');
            }
            $count = trim($units);
            if (!ctype_digit($count) || (int) $count < 1 || (int) $count > $taken->getCount()) {
                throw new InvalidClosureException('units', \sprintf('%s has %d: take from 1 to %d.', $taken->getName(), $taken->getCount(), $taken->getCount()));
            }
            $closure->setUnitsOf($taken, (int) $count);
        }

        $this->entityManager->persist($closure);
        $this->entityManager->flush();

        return $closure;
    }

    public function remove(Closure $closure): void
    {
        $this->entityManager->remove($closure);
        $this->entityManager->flush();
    }

    /** How many of a room type are free on a night. */
    public function free(RoomType $room, \DateTimeImmutable $night): int
    {
        return $this->freeOf($room, $night, $this->closures->findByPropertyBetween($room->getProperty(), $night, $night), $this->holding($room->getProperty(), $night, $night));
    }

    public function month(Property $property, int $year, int $month): AvailabilityMonth
    {
        $first = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));
        $last = $first->modify('last day of this month');
        $closures = $this->closures->findByPropertyBetween($property, $first, $last);
        $bookings = $this->holding($property, $first, $last);

        $nights = [];
        for ($night = $first; $night <= $last; $night = $night->modify('+1 day')) {
            $nights[] = $night;
        }

        $rows = [];
        foreach ($this->rooms->findOnSaleByProperty($property) as $room) {
            $cells = [];
            foreach ($nights as $night) {
                $free = $this->freeOf($room, $night, $closures, $bookings);
                $cells[] = ['date' => $night, 'free' => $free, 'closed' => 0 === $free];
            }
            $rows[] = ['room' => $room, 'nights' => $cells];
        }

        return new AvailabilityMonth($first, $nights, $rows, $closures);
    }

    public function night(Property $property, \DateTimeImmutable $night): AvailabilityNight
    {
        $closures = $this->closures->findByPropertyBetween($property, $night, $night);
        $bookings = $this->holding($property, $night, $night);
        $rooms = [];
        foreach ($this->rooms->findOnSaleByProperty($property) as $room) {
            $out = [];
            foreach ($closures as $closure) {
                $taken = $closure->takes($room);
                if ($taken > 0) {
                    $out[] = ['label' => $closure->getReason(), 'units' => min($taken, $room->getCount()), 'booking' => null];
                }
            }
            foreach ($bookings as $booking) {
                $taken = self::roomsOf($booking, $room);
                if ($taken > 0) {
                    $out[] = ['label' => $booking->getReference().' · '.$booking->getGuest(), 'units' => $taken, 'booking' => $booking];
                }
            }
            $rooms[] = ['room' => $room, 'free' => $this->freeOf($room, $night, $closures, $bookings), 'out' => $out];
        }

        return new AvailabilityNight($night, $rooms);
    }

    /**
     * @param list<Closure>         $closures
     * @param list<PropertyBooking> $bookings
     */
    private function freeOf(RoomType $room, \DateTimeImmutable $night, array $closures, array $bookings): int
    {
        $free = $room->getCount();
        foreach ($closures as $closure) {
            if ($closure->covers($night)) {
                $free -= $closure->takes($room);
            }
        }
        foreach ($bookings as $booking) {
            if ($booking->covers($night)) {
                $free -= self::roomsOf($booking, $room);
            }
        }

        return max(0, $free);
    }

    /**
     * The bookings that hold rooms now, sleeping any night from the first to the last.
     *
     * @return list<PropertyBooking>
     */
    private function holding(Property $property, \DateTimeImmutable $first, \DateTimeImmutable $last): array
    {
        $now = $this->clock->now();

        return array_values(array_filter($this->bookings->findHoldingBetween($property, $first, $last), static fn (PropertyBooking $booking): bool => $booking->holdsRooms($now)));
    }

    private static function roomsOf(PropertyBooking $booking, RoomType $room): int
    {
        $rooms = 0;
        foreach ($booking->getLines() as $line) {
            if ($line->getRoomType()->getId() === $room->getId()) {
                $rooms += $line->getRooms();
            }
        }

        return $rooms;
    }

    /**
     * @throws InvalidClosureException
     */
    private static function date(string $typed, string $field): \DateTimeImmutable
    {
        $typed = trim($typed);
        $date = 1 === preg_match('{^\d{4}-\d{2}-\d{2}$}D', $typed) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $typed) : false;
        if (false === $date || $date->format('Y-m-d') !== $typed) {
            throw new InvalidClosureException($field, 'A date is a day of the calendar: 2027-04-01.');
        }

        return $date;
    }
}
