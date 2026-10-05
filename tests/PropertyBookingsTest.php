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

namespace Vivutio\Property\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Enum\BookingStatusEnum;
use Vivutio\Property\Exception\InvalidBookingException;
use Vivutio\Property\Model\BookingDetails;
use Vivutio\Property\Model\PropertyDetails;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Repository\PropertyBookingRepository;
use Vivutio\Property\Repository\PropertyRepository;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Service\AvailabilityService;
use Vivutio\Property\Service\CancellationService;
use Vivutio\Property\Service\PropertyBookingService;
use Vivutio\Property\Service\PropertyService;
use Vivutio\Property\Service\RateService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonService;
use Vivutio\Property\Stay\PropertyStays;
use Vivutio\Property\Stay\PropertyUnits;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * Property bookings received: priced when made and the total kept; provisional,
 * held until a date, or confirmed; their rooms off the calendar while they
 * hold them; refused where the rooms are not free; cancelled at the charge of
 * the terms they were made under, and a hold cancelled free.
 */
final class PropertyBookingsTest extends KernelTestCase
{
    private MockClock $clock;
    private Property $lodge;
    private RoomType $tented;
    private RoomType $family;
    private SeasonPeriod $high;
    private SeasonPeriod $green;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-07-01 09:00');
        static::getContainer()->set('clock', $this->clock);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output), $output->fetch());

        $this->lodge = $this->service(PropertyService::class)->create('Vivutio Lakeshore Lodge', 'tented_lodge', 'Lake Manyara, Tanzania');
        $rooms = $this->service(RoomTypeService::class);
        $this->tented = $rooms->create($this->lodge, new RoomTypeDetails(name: 'Tented Room', sleeps: '2', adults: '2', count: '20'));
        $this->family = $rooms->create($this->lodge, new RoomTypeDetails(name: 'Family Tent', sleeps: '4', adults: '3', count: '4'));
        $seasons = $this->service(SeasonService::class);
        $this->high = $seasons->addPeriod($seasons->create($this->lodge, 'High Season', 'high'), '2026-06-01', '2026-10-31');
        $this->green = $seasons->addPeriod($seasons->create($this->lodge, 'Green Season', 'low'), '2026-11-01', '2027-05-31');

        $rates = $this->service(RateService::class);
        $rates->setup($this->lodge, 'USD', 'per_person', ['full_board']);
        $rates->saveRates($this->lodge, BoardBasisEnum::FullBoard, [
            (string) $this->tented->getUuid() => [(string) $this->high->getUuid() => '290', (string) $this->green->getUuid() => '210'],
            (string) $this->family->getUuid() => [(string) $this->high->getUuid() => '450'],
        ]);
        $rates->saveTerms($this->lodge, [(string) $this->high->getUuid() => ['single' => '140', 'third_adult' => '70', 'child_sharing' => '50', 'child_own_room' => '75', 'infant' => '0']]);
        $this->service(CancellationService::class)->changeProperty($this->lodge, [['60', '20'], ['29', '100']]);
        $this->service(PropertyService::class)->change($this->lodge, $this->openDetails());
    }

    public function testABookingIsPricedWhenMadeAndKeepsItsTotal(): void
    {
        $booking = $this->book(['status' => 'confirmed', 'lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '2', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => 'full_board']]]);

        self::assertSame('VLL-0001', $booking->getReference());
        self::assertSame(BookingStatusEnum::Confirmed, $booking->getStatus());
        self::assertSame('2026-11-02', $booking->getDeparture()->format('Y-m-d'));
        self::assertSame(316000, $booking->getTotal(), 'two Tented Rooms for three nights across the seasons');
        self::assertSame('USD', $booking->getCurrency());

        $this->service(RateService::class)->saveRates($this->lodge, BoardBasisEnum::FullBoard, [(string) $this->tented->getUuid() => [(string) $this->high->getUuid() => '999']]);
        self::assertSame(316000, $this->reloaded($booking)->getTotal(), 'a later rate never alters a booking made');
        self::assertSame('VLL-0002', $this->book()->getReference());
    }

    public function testItsRoomsComeOffTheCalendarAndABookingIsRefusedWhereTheyAreNotFree(): void
    {
        $this->book(['lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '2', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => 'full_board']]]);
        $availability = $this->service(AvailabilityService::class);

        self::assertSame(18, $availability->free($this->tented, new \DateTimeImmutable('2026-10-30')));
        self::assertSame(20, $availability->free($this->tented, new \DateTimeImmutable('2026-11-02')), 'the night of departure is not slept');

        try {
            $this->book(['arrival' => '2026-11-01', 'lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '19', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => 'full_board']]]);
            self::fail('nineteen Tented Rooms were booked where eighteen are free');
        } catch (InvalidBookingException $refusal) {
            self::assertSame('lines[0][rooms]', $refusal->field);
            self::assertStringContainsString('Tented Room has 18 free on 1 Nov 2026', $refusal->getMessage());
        }
    }

    /** A hold takes its rooms until its date, then lets them go by itself; it can still be confirmed while they are free. */
    public function testAProvisionalBookingHoldsItsRoomsUntilItsDate(): void
    {
        $booking = $this->book(['status' => 'provisional', 'held_until' => '2026-07-15']);
        $availability = $this->service(AvailabilityService::class);
        self::assertSame(19, $availability->free($this->tented, new \DateTimeImmutable('2026-10-30')));

        $this->clock->modify('2026-07-16 08:00');
        self::assertTrue($this->reloaded($booking)->isLapsed($this->clock->now()));
        self::assertSame(20, $availability->free($this->tented, new \DateTimeImmutable('2026-10-30')), 'a lapsed hold holds nothing');

        $this->service(PropertyBookingService::class)->confirm($this->reloaded($booking));
        self::assertSame(BookingStatusEnum::Confirmed, $this->reloaded($booking)->getStatus());
        self::assertSame(19, $availability->free($this->tented, new \DateTimeImmutable('2026-10-30')));
    }

    /** Cancelling is charged by the terms in force when the booking was made; a hold is cancelled free. */
    public function testCancellingIsChargedByTheTermsItWasMadeUnder(): void
    {
        $booking = $this->book(['status' => 'confirmed', 'lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '2', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => 'full_board']]]);
        $this->service(CancellationService::class)->changeProperty($this->fresh(), []);
        $this->clock->modify('2026-09-15 10:00');

        $charged = $this->service(PropertyBookingService::class)->cancel($this->reloaded($booking), 'The guests changed their plans');

        self::assertSame(63200, $charged, '20% of the three nights of two rooms, under the tiers it was made under');
        $booking = $this->reloaded($booking);
        self::assertSame(BookingStatusEnum::Cancelled, $booking->getStatus());
        self::assertSame(63200, $booking->getCancellationCharge());
        self::assertSame(20, $this->service(AvailabilityService::class)->free($this->tented, new \DateTimeImmutable('2026-10-30')));

        $hold = $this->book(['status' => 'provisional', 'held_until' => '2026-09-30']);
        self::assertSame(0, $this->service(PropertyBookingService::class)->cancel($hold, 'Released'));
    }

    /** A booking is a stay a front desk works from, through the core's contracts, and the room types are the units it puts guests in. */
    public function testABookingIsAStayAndTheRoomTypesAreUnits(): void
    {
        $confirmed = $this->book(['lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '2', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => 'full_board']]]);
        $this->book(['status' => 'provisional', 'held_until' => '2026-07-15', 'arrival' => '2026-12-01']);
        $stays = new PropertyStays($this->service(PropertyRepository::class), $this->service(PropertyBookingRepository::class));
        $lodge = $this->fresh();

        $found = [...$stays->at($lodge, new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-02'))];
        self::assertCount(1, $found, 'leaving on the day counts');
        self::assertSame('VLL-0001', $found[0]->getReference());
        self::assertSame([['type' => (string) $this->tented->getUuid(), 'name' => 'Tented Room', 'count' => 2]], $found[0]->getUnits());
        self::assertTrue($found[0]->isExpected());
        self::assertSame((string) $confirmed->getUuid(), $stays->find((string) $confirmed->getUuid())?->getStayId());
        self::assertFalse([...$stays->at($lodge, new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'))][0]->isExpected(), 'a hold is not expected');

        $units = (new PropertyUnits($this->service(PropertyRepository::class), $this->service(RoomTypeRepository::class)))->units($lodge);
        self::assertSame(['Tented Room' => 20, 'Family Tent' => 4], array_column($units ?? [], 'count', 'name'));
    }

    public function testOnlyAnOpenPropertyTakesBookings(): void
    {
        $this->service(PropertyService::class)->change($this->fresh(), $this->openDetails('closed'));

        $this->expectException(InvalidBookingException::class);
        $this->expectExceptionMessage('not taking new bookings');
        $this->book();
    }

    /** What a booking cannot be is refused beside its field. */
    public function testWhatABookingCannotBeIsRefusedBesideItsField(): void
    {
        foreach ([
            ['guest', ['guest' => ' ']],
            ['arrival', ['arrival' => '2026-06-30']],
            ['arrival', ['arrival' => 'soon']],
            ['nights', ['nights' => '0']],
            ['lines', ['lines' => []]],
            ['lines[0][rooms]', ['lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '0', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => 'full_board']]]],
            ['lines[0][adults]', ['lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '1', 'adults' => '3', 'children' => '0', 'infants' => '0', 'board' => 'full_board']]]],
            ['lines[0][board]', ['lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '1', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => 'half_board']]]],
            ['held_until', ['status' => 'provisional', 'held_until' => '']],
            ['held_until', ['status' => 'provisional', 'held_until' => '2026-10-30']],
            ['status', ['status' => 'maybe']],
        ] as [$field, $changed]) {
            try {
                $this->book($changed);
                self::fail($field.' was accepted');
            } catch (InvalidBookingException $refusal) {
                self::assertSame($field, $refusal->field, $refusal->getMessage());
            }
        }
        self::assertCount(0, $this->em()->getRepository(PropertyBooking::class)->findAll());
    }

    /**
     * @param array<string, mixed> $changed
     */
    private function book(array $changed = []): PropertyBooking
    {
        $values = [...[
            'guest' => 'Mollel party',
            'booked_by' => 'Vivutio Tours',
            'their_reference' => 'VT-2026-118',
            'arrival' => '2026-10-30',
            'nights' => '3',
            'status' => 'confirmed',
            'held_until' => '',
            'notes' => '',
            'lines' => [['room' => (string) $this->tented->getUuid(), 'rooms' => '1', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => 'full_board']],
        ], ...$changed];

        return $this->service(PropertyBookingService::class)->record($this->fresh(), BookingDetails::fromForm($values));
    }

    private function openDetails(string $status = 'open'): PropertyDetails
    {
        return new PropertyDetails(name: 'Vivutio Lakeshore Lodge', type: 'tented_lodge', location: 'Lake Manyara, Tanzania', status: $status);
    }

    private function fresh(): Property
    {
        $this->em()->clear();
        $property = $this->em()->getRepository(Property::class)->findOneBy(['name' => 'Vivutio Lakeshore Lodge']);
        self::assertInstanceOf(Property::class, $property);

        return $property;
    }

    private function reloaded(PropertyBooking $booking): PropertyBooking
    {
        $this->em()->clear();
        $fresh = $this->em()->getRepository(PropertyBooking::class)->find($booking->getId());
        self::assertInstanceOf(PropertyBooking::class, $fresh);

        return $fresh;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
