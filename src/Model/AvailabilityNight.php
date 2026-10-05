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

namespace Vivutio\Property\Model;

use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Entity\RoomType;

/**
 * One night of a property: each room type on sale, how many are free, and
 * what takes the rest, closures and bookings.
 */
final readonly class AvailabilityNight
{
    /**
     * @param list<array{room: RoomType, free: int, out: list<array{label: string, units: int, booking: PropertyBooking|null}>}> $rooms
     */
    public function __construct(
        public \DateTimeImmutable $night,
        public array $rooms,
    ) {
    }
}
