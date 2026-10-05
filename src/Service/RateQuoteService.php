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

use Vivutio\Property\Entity\RateTerms;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Enum\PricingEnum;
use Vivutio\Property\Exception\QuoteRefusedException;
use Vivutio\Property\Model\Quote;
use Vivutio\Property\Repository\RateRepository;
use Vivutio\Property\Repository\RateTermsRepository;
use Vivutio\Property\Repository\SeasonPeriodRepository;

/**
 * What a stay in one room costs, each night priced by the season it is in.
 *
 * Per room, the room costs its rate. Per person sharing, two adults pay the
 * rate each; a guest alone, a third adult and more, children (sharing with an
 * adult, or in a room of their own) and infants each pay the period's share of
 * it. Infants take no bed, so the room's beds count the others. What cannot be
 * priced is refused with its reason, never guessed.
 */
final readonly class RateQuoteService
{
    public const int LONGEST_STAY = 90;

    private const array TERM_SAID = [
        'single' => 'a guest alone',
        'third_adult' => 'a third adult',
        'child_sharing' => 'a child sharing',
        'child_own_room' => 'a child in a room of their own',
        'infant' => 'an infant',
    ];

    public function __construct(
        private RateRepository $rates,
        private RateTermsRepository $terms,
        private SeasonPeriodRepository $periods,
    ) {
    }

    /**
     * @throws QuoteRefusedException
     */
    public function quote(RoomType $room, BoardBasisEnum $board, \DateTimeImmutable $arrival, int $nights, int $adults, int $children, int $infants): Quote
    {
        $property = $room->getProperty();
        $currency = $property->getCurrency() ?? throw new QuoteRefusedException(\sprintf('%s has no rate sheet yet.', $property->getName()));
        if (!\in_array($board->value, $property->getBoards(), true)) {
            throw new QuoteRefusedException(\sprintf('%s does not sell %s.', $property->getName(), mb_strtolower($board->label())));
        }
        if ($nights < 1 || $nights > self::LONGEST_STAY) {
            throw new QuoteRefusedException(\sprintf('A stay is at least one night and at most %d.', self::LONGEST_STAY));
        }
        if ($adults < 0 || $children < 0 || $infants < 0 || $adults + $children < 1) {
            throw new QuoteRefusedException('A stay is for at least one guest, an adult or a child.');
        }
        if ($adults > $room->getAdults()) {
            throw new QuoteRefusedException(\sprintf('%s takes %d %s at most.', $room->getName(), $room->getAdults(), 1 === $room->getAdults() ? 'adult' : 'adults'));
        }
        if ($adults + $children > $room->getSleeps()) {
            throw new QuoteRefusedException(\sprintf('%s sleeps %d, infants aside.', $room->getName(), $room->getSleeps()));
        }

        $periods = $this->periods->findByProperty($property);
        $priced = [];
        $total = 0;
        $night = $arrival->setTime(0, 0);
        for ($i = 0; $i < $nights; ++$i, $night = $night->modify('+1 day')) {
            $period = self::periodOf($periods, $night) ?? throw new QuoteRefusedException(\sprintf('%s is in no season, so it has no rate.', $night->format('j M Y')));
            $rate = $this->rates->findOneBy(['roomType' => $room, 'period' => $period, 'board' => $board]) ?? throw new QuoteRefusedException(\sprintf('%s has no %s rate for %s.', $room->getName(), $board->label(), self::called($period)));
            $base = self::cents($rate->getAmount());

            $lines = PricingEnum::PerRoom === $property->getPricing()
                ? ['1 room' => $base]
                : $this->perPerson($period, $base, $adults, $children, $infants);
            $cost = array_sum($lines);
            $priced[] = ['date' => $night, 'season' => $period->getSeason()->getName(), 'lines' => $lines, 'total' => $cost];
            $total += $cost;
        }

        return new Quote($currency, $priced, $total);
    }

    /**
     * @return array<string, int>
     *
     * @throws QuoteRefusedException
     */
    private function perPerson(SeasonPeriod $period, int $base, int $adults, int $children, int $infants): array
    {
        $terms = $this->terms->findOneBy(['period' => $period]);
        $share = static function (string $term) use ($terms, $period): int {
            $share = $terms instanceof RateTerms ? $terms->share($term) : null;

            return $share ?? throw new QuoteRefusedException(\sprintf('%s has no terms for %s.', self::called($period), self::TERM_SAID[$term]));
        };
        $of = static fn (int $count, int $percent): int => $count * intdiv($base * $percent + 50, 100);

        if (1 === $adults + $children) {
            $lines = [1 === $adults ? '1 guest alone' : '1 child alone' => $of(1, $share(1 === $adults ? 'single' : 'child_own_room'))];
        } else {
            $lines = [];
            $sharing = min($adults, 2);
            if ($sharing > 0) {
                $lines[$sharing.' '.(1 === $sharing ? 'adult' : 'adults').' sharing'] = $of($sharing, 100);
            }
            if ($adults > 2) {
                $lines[3 === $adults ? '1 third adult' : ($adults - 2).' adults beyond two'] = $of($adults - 2, $share('third_adult'));
            }
            if ($children > 0) {
                $lines[$children.' '.(1 === $children ? 'child' : 'children').($adults > 0 ? ' sharing' : ' in a room of their own')] = $of($children, $share($adults > 0 ? 'child_sharing' : 'child_own_room'));
            }
        }
        if ($infants > 0) {
            $lines[$infants.' '.(1 === $infants ? 'infant' : 'infants')] = $of($infants, $share('infant'));
        }

        return $lines;
    }

    /**
     * @param list<SeasonPeriod> $periods
     */
    private static function periodOf(array $periods, \DateTimeImmutable $night): ?SeasonPeriod
    {
        foreach ($periods as $period) {
            if ($period->getStarts() <= $night && $period->getEnds() >= $night) {
                return $period;
            }
        }

        return null;
    }

    /** "Green Season (1 Nov 2026 – 31 May 2027)". */
    private static function called(SeasonPeriod $period): string
    {
        return \sprintf('%s (%s)', $period->getSeason()->getName(), SeasonService::span($period->getStarts(), $period->getEnds()));
    }

    private static function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
