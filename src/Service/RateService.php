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
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\Rate;
use Vivutio\Property\Entity\RateTerms;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Enum\PricingEnum;
use Vivutio\Property\Exception\InvalidRateException;
use Vivutio\Property\Model\RatesCarried;
use Vivutio\Property\Repository\RateRepository;
use Vivutio\Property\Repository\RateTermsRepository;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Repository\SeasonPeriodRepository;

/**
 * A property's rate sheet: one currency, priced per person sharing or per
 * room, on the board bases it sells; a rate per room type, season period and
 * board basis; and each period's terms. What its rates mean is not changed
 * under them: the currency and the pricing stay while there are rates, and a
 * board basis with rates is not dropped.
 */
final readonly class RateService
{
    public const int MOST_SHARE = 300;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RateRepository $rates,
        private RateTermsRepository $terms,
        private RoomTypeRepository $rooms,
        private SeasonPeriodRepository $periods,
    ) {
    }

    /**
     * @param list<string> $boards
     *
     * @throws InvalidRateException
     */
    public function setup(Property $property, string $currency, string $pricing, array $boards): void
    {
        $currency = strtoupper(trim($currency));
        if (1 !== preg_match('{^[A-Z]{3}$}D', $currency)) {
            throw new InvalidRateException('currency', 'A currency is its three-letter code: USD, TZS, KES.');
        }
        $priced = PricingEnum::tryFrom($pricing) ?? throw new InvalidRateException('pricing', 'Choose what a rate is the price of.');
        $sold = [];
        foreach (BoardBasisEnum::cases() as $board) {
            if (\in_array($board->value, $boards, true)) {
                $sold[] = $board->value;
            }
        }
        if ([] === $sold || \count($sold) !== \count(array_unique($boards))) {
            throw new InvalidRateException('boards', 'Choose the board bases it sells, at least one.');
        }

        $rates = $this->rates->findByProperty($property);
        if ([] !== $rates) {
            $said = \sprintf('%d %s', \count($rates), 1 === \count($rates) ? 'rate is' : 'rates are');
            if ($currency !== $property->getCurrency()) {
                throw new InvalidRateException('currency', \sprintf('Its %s in %s: clear them before the currency changes.', $said, $property->getCurrency()));
            }
            if ($priced !== $property->getPricing()) {
                throw new InvalidRateException('pricing', \sprintf('Its %s %s: clear them before that changes.', $said, mb_strtolower($property->getPricing()->label())));
            }
            foreach ($rates as $rate) {
                if (!\in_array($rate->getBoard()->value, $sold, true)) {
                    throw new InvalidRateException('boards', \sprintf('%s has rates: clear them before it is no longer sold.', $rate->getBoard()->label()));
                }
            }
        }

        $property->setCurrency($currency)->setPricing($priced)->setBoards($sold);
        $this->entityManager->flush();
    }

    /**
     * A board basis's rates, by room type and season period, each as typed:
     * an amount sets the rate, an empty one clears it. Nothing is kept when
     * one is refused.
     *
     * @param array<string, array<string, string>> $amounts by room type uuid, then season period uuid
     *
     * @throws InvalidRateException
     */
    public function saveRates(Property $property, BoardBasisEnum $board, array $amounts): int
    {
        if (null === $property->getCurrency() || !\in_array($board->value, $property->getBoards(), true)) {
            throw new InvalidRateException('board', \sprintf('%s does not sell %s.', $property->getName(), mb_strtolower($board->label())));
        }

        $rooms = [];
        foreach ($this->rooms->findByProperty($property) as $room) {
            $rooms[(string) $room->getUuid()] = $room;
        }
        $periods = [];
        foreach ($this->periods->findByProperty($property) as $period) {
            $periods[(string) $period->getUuid()] = $period;
        }

        $changes = [];
        foreach ($amounts as $roomId => $cells) {
            foreach ($cells as $periodId => $typed) {
                $field = \sprintf('amounts[%s][%s]', $roomId, $periodId);
                $room = $rooms[$roomId] ?? throw new InvalidRateException($field, 'Choose among its room types.');
                $period = $periods[$periodId] ?? throw new InvalidRateException($field, 'Choose among its season periods.');
                $changes[] = [$room, $period, self::amount(trim($typed), $field)];
            }
        }

        foreach ($changes as [$room, $period, $amount]) {
            $this->put($room, $period, $board, $amount);
        }
        $this->entityManager->flush();

        return \count($changes);
    }

    /**
     * Each period's terms, by term, each a share of the sharing rate in per
     * cent, or empty where it is not sold.
     *
     * @param array<string, array<string, string>> $terms by season period uuid, then term
     *
     * @throws InvalidRateException
     */
    public function saveTerms(Property $property, array $terms): void
    {
        $periods = [];
        foreach ($this->periods->findByProperty($property) as $period) {
            $periods[(string) $period->getUuid()] = $period;
        }

        $changes = [];
        foreach ($terms as $periodId => $shares) {
            $period = $periods[$periodId] ?? throw new InvalidRateException(\sprintf('terms[%s]', $periodId), 'Choose among its season periods.');
            $set = [];
            foreach ($shares as $term => $typed) {
                $field = \sprintf('terms[%s][%s]', $periodId, $term);
                if (!\in_array($term, RateTerms::TERMS, true)) {
                    throw new InvalidRateException($field, 'Choose among the terms offered.');
                }
                $typed = trim($typed);
                if ('' === $typed) {
                    continue;
                }
                if (!ctype_digit($typed) || (int) $typed > self::MOST_SHARE) {
                    throw new InvalidRateException($field, \sprintf('A share of the sharing rate, from 0 to %d per cent.', self::MOST_SHARE));
                }
                $set[$term] = (int) $typed;
            }
            $changes[] = [$period, $set];
        }

        foreach ($changes as [$period, $set]) {
            $found = $this->terms->findOneBy(['period' => $period]);
            if (null === $found) {
                $found = new RateTerms($period);
                $this->entityManager->persist($found);
            }
            $found->setShares($set);
        }
        $this->entityManager->flush();
    }

    /**
     * Each rate and each period's terms of the periods that start in a year,
     * onto its season's period a year later, raised by a share in per cent;
     * whatever is set there already is left as it is.
     *
     * @throws InvalidRateException
     */
    public function carry(Property $property, int $year, string $raise): RatesCarried
    {
        $raise = trim($raise);
        if ('' === $raise) {
            $raise = '0';
        }
        if (!ctype_digit($raise) || (int) $raise > 100) {
            throw new InvalidRateException('raise', 'A raise is a whole share, from 0 to 100 per cent.');
        }
        $by = (int) $raise;

        $periods = $this->periods->findByProperty($property);
        $starting = array_filter($periods, static fn (SeasonPeriod $period): bool => (int) $period->getStarts()->format('Y') === $year);

        $unmatched = 0;
        $copied = 0;
        $left = 0;
        foreach ($starting as $period) {
            $next = null;
            foreach ($periods as $candidate) {
                if ($candidate->getSeason() === $period->getSeason() && $candidate->getStarts() == SeasonService::aYearOn($period->getStarts())) {
                    $next = $candidate;
                }
            }
            if (null === $next) {
                ++$unmatched;
                continue;
            }

            foreach ($this->rates->findBy(['period' => $period]) as $rate) {
                if (null !== $this->rates->findOneBy(['roomType' => $rate->getRoomType(), 'period' => $next, 'board' => $rate->getBoard()])) {
                    ++$left;
                    continue;
                }
                $copy = (new Rate($rate->getRoomType(), $next, $rate->getBoard()))->setAmount(self::raised($rate->getAmount(), $by));
                $this->entityManager->persist($copy);
                $this->entityManager->flush();
                ++$copied;
            }

            $terms = $this->terms->findOneBy(['period' => $period]);
            if (null !== $terms && null === $this->terms->findOneBy(['period' => $next])) {
                $this->entityManager->persist((new RateTerms($next))->setShares($terms->getShares()));
                $this->entityManager->flush();
            }
        }

        return new RatesCarried($year, $by, \count($starting), $unmatched, $copied, $left);
    }

    /** An amount raised by a share, to the cent, halves up: "290.00" by 5 is "304.50". */
    private static function raised(string $amount, int $by): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $cents = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        $raised = intdiv($cents * (100 + $by) + 50, 100);

        return \sprintf('%d.%02d', intdiv($raised, 100), $raised % 100);
    }

    /**
     * An amount to the cent as stored, "290.00", or null to clear.
     *
     * @throws InvalidRateException
     */
    private static function amount(string $typed, string $field): ?string
    {
        if ('' === $typed) {
            return null;
        }
        if (1 !== preg_match('{^\d{1,7}(\.\d{1,2})?$}D', $typed)) {
            throw new InvalidRateException($field, 'A rate is an amount of money, to the cent: 290 or 290.50.');
        }

        return number_format((float) $typed, 2, '.', '');
    }

    private function put(RoomType $room, SeasonPeriod $period, BoardBasisEnum $board, ?string $amount): void
    {
        $rate = $this->rates->findOneBy(['roomType' => $room, 'period' => $period, 'board' => $board]);
        if (null === $amount) {
            if (null !== $rate) {
                $this->entityManager->remove($rate);
            }

            return;
        }
        if (null === $rate) {
            $rate = new Rate($room, $period, $board);
            $this->entityManager->persist($rate);
        }
        $rate->setAmount($amount);
    }
}
