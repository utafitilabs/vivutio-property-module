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
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Exception\InvalidCancellationException;
use Vivutio\Property\Model\CancellationCharge;
use Vivutio\Property\Model\Quote;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Service\CancellationService;
use Vivutio\Property\Service\PropertyService;
use Vivutio\Property\Service\RateQuoteService;
use Vivutio\Property\Service\RateService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * What cancelling a stay costs: tiers counted in days before arrival, each a
 * share of what the stay costs; a season follows its property's tiers, has its
 * own, or charges nothing; a stay across seasons is charged night by night,
 * each night by its own season's tiers.
 */
final class WhatCancellingCostsTest extends KernelTestCase
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

    public function testTheTiersCountDaysBeforeArrival(): void
    {
        $this->policies();
        $stay = $this->quote($this->tented, '2026-07-01', 2, 2);

        foreach ([
            ['2026-04-01', 0, 'more than 60 days'],
            ['2026-05-02', 23200, '60 days'],
            ['2026-05-17', 23200, '45 days'],
            ['2026-05-18', 58000, '44 days'],
            ['2026-06-01', 58000, '30 days'],
            ['2026-06-02', 116000, '29 days'],
            ['2026-07-01', 116000, 'on the night of arrival'],
            ['2026-07-02', 116000, 'after arrival'],
        ] as [$cancelled, $charge, $said]) {
            $cost = $this->cancel($stay, $cancelled);
            self::assertSame($charge, $cost->total, $cancelled);
            self::assertStringContainsString($said, $cost->when(), $cancelled);
        }
    }

    /** Each night by its own season: here the green season charges nothing. */
    public function testAStayAcrossSeasonsIsChargedNightByNight(): void
    {
        $this->policies();
        $stay = $this->quote($this->tented, '2026-10-30', 3, 2);

        $cost = $this->cancel($stay, '2026-09-15');

        self::assertSame(23200, $cost->total, 'two high-season nights at 20%, one green-season night free');
        self::assertSame(['High Season' => [20, 116000, 23200], 'Green Season' => [0, 42000, 0]], $cost->bySeason);
    }

    public function testWithoutTiersCancellingIsFree(): void
    {
        self::assertSame(0, $this->cancel($this->quote($this->tented, '2026-07-01', 2, 2), '2026-07-01')->total);
    }

    /** Tiers say the same thing once, and never charge less for cancelling later. */
    public function testTiersAreRefusedWhereTheyContradictThemselves(): void
    {
        $policies = $this->service(CancellationService::class);

        foreach ([
            [[['60', '50'], ['30', '20']], 'charges less'],
            [[['60', '20'], ['60', '50']], 'once'],
            [[['400', '20']], '365'],
            [[['30', '0']], 'from 1 to 100'],
            [[['30', '101']], 'from 1 to 100'],
            [[['soon', '50']], 'whole number of days'],
        ] as [$tiers, $said]) {
            try {
                $policies->changeProperty($this->lodge, $tiers);
                self::fail(json_encode($tiers).' was accepted');
            } catch (InvalidCancellationException $refusal) {
                self::assertStringContainsString($said, $refusal->getMessage());
            }
        }
    }

    public function testTiersAreSaidAsBands(): void
    {
        $this->policies();

        self::assertSame([
            'Free more than 60 days before arrival',
            '20% from 60 to 45 days before',
            '50% from 44 to 30 days before',
            '100% from 29 days before, and after arrival',
        ], $this->service(CancellationService::class)->bands($this->lodge->getCancellation()));
        self::assertSame(['No charge to cancel'], $this->service(CancellationService::class)->bands([]));
    }

    private function policies(): void
    {
        $policies = $this->service(CancellationService::class);
        $policies->changeProperty($this->lodge, [['60', '20'], ['44', '50'], ['29', '100']]);
        $policies->changeSeason($this->green->getSeason(), 'no_charge', []);
    }

    private function cancel(Quote $stay, string $on): CancellationCharge
    {
        return $this->service(CancellationService::class)->charge($stay, new \DateTimeImmutable($on));
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
