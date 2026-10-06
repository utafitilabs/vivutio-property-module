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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vivutio\Bundle\PlaceBundle\Service\NightCostService;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Exception\QuoteRefusedException;
use Vivutio\Property\Model\Quote;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Service\PropertyService;
use Vivutio\Property\Service\RateQuoteService;
use Vivutio\Property\Service\RateService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * What a stay costs: each night priced by the season it is in, at the rate of
 * the room type and board basis, per person sharing with the period's terms
 * for a guest alone, a third adult, children and infants, or per room.
 */
final class WhatAStayCostsTest extends KernelTestCase
{
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
    }

    /** Night by night, so a stay across a season's end is priced by both. */
    public function testEachNightIsPricedByItsSeason(): void
    {
        $quote = $this->quote($this->tented, '2026-10-30', 3, 2);

        self::assertSame(['2026-10-30' => 58000, '2026-10-31' => 58000, '2026-11-01' => 42000], $quote->nightly());
        self::assertSame(158000, $quote->total);
        self::assertSame('USD 1,580.00', $quote->says($quote->total));
        self::assertSame(['High Season', 'High Season', 'Green Season'], array_map(static fn (array $night): string => $night['season'], $quote->nights));
    }

    public function testTheTermsPriceAGuestAloneAThirdAdultChildrenAndInfants(): void
    {
        self::assertSame(40600, $this->quote($this->tented, '2026-07-01', 1, 1)->total, 'a guest alone pays 140%');
        self::assertSame(45000 + 45000 + 31500 + 22500, $this->quote($this->family, '2026-07-01', 1, 3, 1)->total, 'two adults, a third at 70%, a child sharing at 50%');
        self::assertSame(29000 + 29000, $this->quote($this->tented, '2026-07-01', 1, 2, 0, 1)->total, 'an infant at 0%');
        self::assertSame(['2 adults sharing' => 90000, '1 third adult' => 31500, '1 child sharing' => 22500], array_map(static fn (int $cents): int => $cents, $this->quote($this->family, '2026-07-01', 1, 3, 1)->nights[0]['lines']));
    }

    /** What cannot be priced is refused with the reason, never guessed. */
    public function testWhatCannotBePricedIsRefusedWithItsReason(): void
    {
        foreach ([
            [$this->tented, '2026-03-01', 1, 2, 0, '1 Mar 2026 is in no season'],
            [$this->tented, '2026-12-01', 1, 1, 0, 'Green Season (1 Nov 2026 – 31 May 2027) has no terms for a guest alone'],
            [$this->family, '2026-12-01', 1, 2, 0, 'Family Tent has no Full board rate for Green Season (1 Nov 2026 – 31 May 2027)'],
            [$this->tented, '2026-07-01', 1, 3, 0, 'Tented Room takes 2 adults at most'],
            [$this->tented, '2026-07-01', 1, 2, 1, 'Tented Room sleeps 2'],
            [$this->tented, '2026-07-01', 0, 2, 0, 'at least one night'],
        ] as [$room, $arrival, $nights, $adults, $children, $said]) {
            try {
                $this->quote($room, $arrival, $nights, $adults, $children);
                self::fail($said.' was priced');
            } catch (QuoteRefusedException $refusal) {
                self::assertStringContainsString($said, $refusal->getMessage());
            }
        }
    }

    /** Priced per room, a room costs its rate whoever is in it, and no terms are asked for. */
    public function testPerRoomARoomCostsItsRate(): void
    {
        $camp = $this->service(PropertyService::class)->create('Vivutio Riverside Camp', 'tented_camp', 'Central Serengeti, Tanzania');
        $tent = $this->service(RoomTypeService::class)->create($camp, new RoomTypeDetails(name: 'Tent', sleeps: '2', adults: '2', count: '10'));
        $seasons = $this->service(SeasonService::class);
        $period = $seasons->addPeriod($seasons->create($camp, 'High Season', 'high'), '2026-06-01', '2026-10-31');
        $rates = $this->service(RateService::class);
        $rates->setup($camp, 'USD', 'per_room', ['half_board']);
        $rates->saveRates($camp, BoardBasisEnum::HalfBoard, [(string) $tent->getUuid() => [(string) $period->getUuid() => '400']]);

        self::assertSame(80000, $this->service(RateQuoteService::class)->quote($tent, BoardBasisEnum::HalfBoard, new \DateTimeImmutable('2026-07-01'), 2, 1, 0, 0)->total);
    }

    /** A tour asks the core what a night costs a person; the property answers from its cheapest room for two. */
    public function testATourIsToldWhatANightCostsAPersonSharing(): void
    {
        $costs = $this->service(NightCostService::class);
        $lodge = 'property:'.$this->lodge->getUuid();

        $high = $costs->costOf($lodge, new \DateTimeImmutable('2026-07-01'));
        self::assertNotNull($high);
        self::assertSame(['USD', 29000, 'Tented Room, full board, sharing'], [$high->currency, $high->each, $high->basis]);
        self::assertSame(21000, $costs->costOf($lodge, new \DateTimeImmutable('2026-12-01'))?->each, 'the Family Tent has no Green Season rate');
        self::assertNull($costs->costOf($lodge, new \DateTimeImmutable('2026-03-01')), 'a night in no season');
    }

    private function quote(RoomType $room, string $arrival, int $nights, int $adults, int $children = 0, int $infants = 0): Quote
    {
        return $this->service(RateQuoteService::class)->quote($room, BoardBasisEnum::FullBoard, new \DateTimeImmutable($arrival), $nights, $adults, $children, $infants);
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
