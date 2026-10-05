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
use Vivutio\Property\Entity\Season;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\SeasonKindEnum;
use Vivutio\Property\Exception\InvalidSeasonException;
use Vivutio\Property\Model\YearRepeated;
use Vivutio\Property\Repository\RateRepository;
use Vivutio\Property\Repository\SeasonPeriodRepository;
use Vivutio\Property\Repository\SeasonRepository;

/**
 * A property's seasons and when they run. A season is named once in its
 * property and is of a kind; a period runs from its first night to its last,
 * a year at most, and no night of a property is in two periods, so the rate
 * of a night is never in doubt.
 */
final readonly class SeasonService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SeasonRepository $seasons,
        private SeasonPeriodRepository $periods,
        private RateRepository $rates,
    ) {
    }

    /**
     * @throws InvalidSeasonException
     */
    public function create(Property $property, string $name, string $kind): Season
    {
        $season = new Season($property);
        $season->setName($this->name($season, $name))->setKind($this->kind($kind));

        $this->entityManager->persist($season);
        $this->entityManager->flush();

        return $season;
    }

    /**
     * @throws InvalidSeasonException
     */
    public function change(Season $season, string $name, string $kind): void
    {
        $season->setName($this->name($season, $name))->setKind($this->kind($kind));
        $this->entityManager->flush();
    }

    /**
     * Its first night and its last, as a form sends them: 2026-06-01.
     *
     * @throws InvalidSeasonException
     */
    public function addPeriod(Season $season, string $starts, string $ends): SeasonPeriod
    {
        $first = $this->date($starts, 'starts');
        $last = $this->date($ends, 'ends');
        if ($last < $first) {
            throw new InvalidSeasonException('ends', 'A period ends after it starts, or on the same night.');
        }
        if ($last >= $first->modify('+1 year')) {
            throw new InvalidSeasonException('ends', 'A period runs for a year at most; add another for the next.');
        }

        $clash = $this->clash($season->getProperty(), $first, $last);
        if (null !== $clash) {
            throw new InvalidSeasonException('starts', \sprintf('%s runs %s then: a night is in one season at most.', $clash->getSeason()->getName(), self::span($clash->getStarts(), $clash->getEnds())));
        }

        $period = new SeasonPeriod($season, $first, $last);
        $season->getPeriods()->add($period);
        $this->entityManager->persist($period);
        $this->entityManager->flush();

        return $period;
    }

    /**
     * A period is removed while nothing is priced by it.
     *
     * @throws InvalidSeasonException
     */
    public function removePeriod(SeasonPeriod $period): void
    {
        $priced = $this->rates->count(['period' => $period]);
        if ($priced > 0) {
            throw new InvalidSeasonException('season', \sprintf('%s has %d %s on the Rates tab: clear them before the period is removed.', self::span($period->getStarts(), $period->getEnds()), $priced, 1 === $priced ? 'rate' : 'rates'));
        }

        $period->getSeason()->getPeriods()->removeElement($period);
        $this->entityManager->remove($period);
        $this->entityManager->flush();
    }

    /**
     * A season goes once it runs no period.
     *
     * @throws InvalidSeasonException
     */
    public function remove(Season $season): void
    {
        if (!$season->getPeriods()->isEmpty()) {
            throw new InvalidSeasonException('season', \sprintf('%s still runs %d %s: remove them first.', $season->getName(), $season->getPeriods()->count(), 1 === $season->getPeriods()->count() ? 'period' : 'periods'));
        }

        $this->entityManager->remove($season);
        $this->entityManager->flush();
    }

    /**
     * Every period that starts in a year, again a year later, leaving out any
     * that would overlap a period already there.
     */
    public function repeat(Property $property, int $year): YearRepeated
    {
        $found = array_filter($this->periods->findByProperty($property), static fn (SeasonPeriod $period): bool => (int) $period->getStarts()->format('Y') === $year);

        $copied = 0;
        foreach ($found as $period) {
            $first = self::aYearOn($period->getStarts());
            $last = self::aYearOn($period->getEnds());
            if (null !== $this->clash($property, $first, $last)) {
                continue;
            }
            $copy = new SeasonPeriod($period->getSeason(), $first, $last);
            $period->getSeason()->getPeriods()->add($copy);
            $this->entityManager->persist($copy);
            $this->entityManager->flush();
            ++$copied;
        }

        return new YearRepeated($year, \count($found), $copied);
    }

    /** "1 Jun – 31 Oct 2026", or "1 Nov 2026 – 31 May 2027" across a year's end. */
    public static function span(\DateTimeImmutable $first, \DateTimeImmutable $last): string
    {
        return $first->format('Y') === $last->format('Y')
            ? $first->format('j M').' – '.$last->format('j M Y')
            : $first->format('j M Y').' – '.$last->format('j M Y');
    }

    private function clash(Property $property, \DateTimeImmutable $first, \DateTimeImmutable $last): ?SeasonPeriod
    {
        foreach ($this->periods->findByProperty($property) as $other) {
            if ($other->getStarts() <= $last && $other->getEnds() >= $first) {
                return $other;
            }
        }

        return null;
    }

    /** The same day a year later; the 29th of February becomes the 28th. */
    public static function aYearOn(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $next = (int) $day->format('Y') + 1;
        $date = $day->setDate($next, (int) $day->format('n'), 1);

        return $date->setDate($next, (int) $day->format('n'), min((int) $day->format('j'), (int) $date->format('t')));
    }

    /**
     * @throws InvalidSeasonException
     */
    private function name(Season $season, string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidSeasonException('name', 'A season is known by its name: it cannot be empty.');
        }
        if (mb_strlen($name) > Season::NAME_MAX_LENGTH) {
            throw new InvalidSeasonException('name', \sprintf('A name can be at most %d characters.', Season::NAME_MAX_LENGTH));
        }
        foreach ($this->seasons->findByProperty($season->getProperty()) as $other) {
            if ($other !== $season && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new InvalidSeasonException('name', \sprintf('%s already has a season called %s.', $season->getProperty()->getName(), $other->getName()));
            }
        }

        return $name;
    }

    /**
     * @throws InvalidSeasonException
     */
    private function kind(string $kind): SeasonKindEnum
    {
        return SeasonKindEnum::tryFrom($kind) ?? throw new InvalidSeasonException('kind', 'Choose how busy a season it is.');
    }

    /**
     * @throws InvalidSeasonException
     */
    private function date(string $typed, string $field): \DateTimeImmutable
    {
        $date = 1 === preg_match('{^\d{4}-\d{2}-\d{2}$}D', trim($typed)) ? \DateTimeImmutable::createFromFormat('!Y-m-d', trim($typed)) : false;
        if (false === $date || $date->format('Y-m-d') !== trim($typed)) {
            throw new InvalidSeasonException($field, 'A date is a day of the calendar: 2026-06-01.');
        }

        return $date;
    }
}
