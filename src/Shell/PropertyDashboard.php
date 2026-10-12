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

namespace Vivutio\Property\Shell;

use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Shell\DashboardCard;
use Vivutio\Contracts\Shell\DashboardFigure;
use Vivutio\Contracts\Shell\DashboardRow;
use Vivutio\Contracts\Shell\DashboardSourceInterface;
use Vivutio\Property\Controller\BookingController;
use Vivutio\Property\Controller\PropertyController;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Repository\PropertyBookingRepository;

/**
 * The bookings held and still to confirm, on the organization's dashboard: a
 * figure in the band and a card of the soonest to let go, as designed in
 * vivutio-designs/shell/dashboard-supplier.html. Counted over the bookings
 * the person may read, by the same rule as the pages, so reach applies.
 */
final readonly class PropertyDashboard implements DashboardSourceInterface
{
    private const string HELD = 'Held, to confirm';

    public function __construct(
        private PropertyBookingRepository $bookings,
        private PartnerDirectoryInterface $partners,
        private AuthorizationCheckerInterface $authorization,
        private ClockInterface $clock,
    ) {
    }

    public function figures(): iterable
    {
        if (!$this->authorization->isGranted(BookingController::READ)) {
            return;
        }

        $soonest = [0 => 0, 1 => 0];
        foreach ($held = $this->held() as $booking) {
            $days = $this->daysLeft($booking);
            if ($days <= 1) {
                ++$soonest[$days];
            }
        }

        yield new DashboardFigure(self::HELD, (string) \count($held), match (true) {
            $soonest[0] > 0 => self::letsGo($soonest[0], 'today'),
            $soonest[1] > 0 => self::letsGo($soonest[1], 'tomorrow'),
            default => '',
        }, BookingController::READ, 30);
    }

    public function cards(): iterable
    {
        if (!$this->authorization->isGranted(BookingController::READ)) {
            return;
        }

        $held = $this->held();
        $rows = [];
        foreach (\array_slice($held, 0, DashboardCard::MOST_ROWS) as $booking) {
            [$chip, $tone] = self::chip($this->daysLeft($booking));
            $rows[] = new DashboardRow(
                $booking->getReference(),
                \sprintf('%s · %s', $this->party($booking), $booking->getProperty()->getName()),
                [\sprintf('held to %s', $booking->getHeldUntil()?->format('D j M'))],
                $chip,
                $tone,
            );
        }

        yield new DashboardCard(
            title: self::HELD,
            kicker: 'Bookings',
            caption: 'the soonest let go first',
            rows: $rows,
            total: \count($held),
            empty: 'Nothing is held',
            door: 'All properties',
            route: PropertyController::REGISTER,
            attribute: BookingController::READ,
            order: 20,
        );
    }

    /**
     * @return list<PropertyBooking> the holds not lapsed that the person may read, the soonest to let go first
     */
    private function held(): array
    {
        return array_values(array_filter(
            $this->bookings->findHeld($this->clock->now()->setTime(0, 0)),
            fn (PropertyBooking $booking): bool => $this->authorization->isGranted(BookingController::READ, $booking),
        ));
    }

    /** Whole days from today to the last day of its hold: 0 when it lets go today. */
    private function daysLeft(PropertyBooking $booking): int
    {
        $day = static fn (\DateTimeImmutable $moment): \DateTimeImmutable => new \DateTimeImmutable($moment->format('Y-m-d'), new \DateTimeZone('UTC'));
        $until = $booking->getHeldUntil() ?? $this->clock->now();

        return (int) $day($this->clock->now())->diff($day($until))->days;
    }

    /**
     * @return array{0: string, 1: string} the chip's words and its tone
     */
    private static function chip(int $days): array
    {
        return match (true) {
            0 === $days => ['today', 'danger'],
            1 === $days => ['tomorrow', 'danger'],
            $days <= 3 => [\sprintf('in %d days', $days), 'warn'],
            7 === $days => ['in a week', ''],
            default => [\sprintf('in %d days', $days), ''],
        };
    }

    private static function letsGo(int $count, string $when): string
    {
        return \sprintf('%d %s %s', $count, 1 === $count ? 'lets go' : 'let go', $when);
    }

    /** Who it is for: the partner who made it, or the guest of a direct booking. */
    private function party(PropertyBooking $booking): string
    {
        $partner = $booking->getPartnerId();

        return (null === $partner ? null : $this->partners->find($partner)?->getName()) ?? $booking->getGuest();
    }
}
