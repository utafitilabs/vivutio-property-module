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

namespace Vivutio\Property\Partner;

use Vivutio\Contracts\Partner\Charge;
use Vivutio\Contracts\Partner\ChargeSourceInterface;
use Vivutio\Property\Controller\BookingController;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Enum\BookingStatusEnum;
use Vivutio\Property\Repository\PropertyBookingRepository;

/**
 * What bookings received are owed, as charges: a confirmed booking its total,
 * from the day it was made, due then from a guest and in its partner's days to
 * pay from a partner; a cancelled one what its cancellation kept, from the day
 * it was cancelled; a provisional one nothing yet.
 */
final readonly class PropertyCharges implements ChargeSourceInterface
{
    public const string KEY = 'property_booking';

    public function __construct(private PropertyBookingRepository $bookings)
    {
    }

    public function charges(): iterable
    {
        foreach ($this->bookings->findBy([], ['arrival' => 'ASC', 'reference' => 'ASC']) as $booking) {
            if (BookingStatusEnum::Provisional === $booking->getStatus()) {
                continue;
            }
            $cancelled = BookingStatusEnum::Cancelled === $booking->getStatus();
            $raised = ($cancelled ? $booking->getCancelledAt() ?? $booking->getMadeAt() : $booking->getMadeAt())->setTime(0, 0);
            yield new Charge(
                self::KEY.':'.$booking->getUuid(),
                $booking->getReference(),
                'Booking',
                $booking->getProperty()->getName().', '.self::dates($booking).($cancelled ? ', cancelled' : ''),
                $booking->getPartnerId(),
                $booking->getGuest(),
                $booking->getCurrency(),
                $cancelled ? $booking->getCancellationCharge() ?? 0 : $booking->getTotal(),
                $raised,
                $raised->modify(\sprintf('+%d days', $cancelled || null === $booking->getPartnerId() ? 0 : $booking->getCreditDays())),
                BookingController::BOOKING,
                ['uuid' => (string) $booking->getProperty()->getUuid(), 'booking' => (string) $booking->getUuid()],
            );
        }
    }

    /** "30 Oct – 2 Nov 2026", arriving to leaving. */
    private static function dates(PropertyBooking $booking): string
    {
        $first = $booking->getArrival();
        $last = $booking->getDeparture();

        return match (true) {
            $first->format('Y-m') === $last->format('Y-m') => $first->format('j').' – '.$last->format('j M Y'),
            $first->format('Y') === $last->format('Y') => $first->format('j M').' – '.$last->format('j M Y'),
            default => $first->format('j M Y').' – '.$last->format('j M Y'),
        };
    }
}
