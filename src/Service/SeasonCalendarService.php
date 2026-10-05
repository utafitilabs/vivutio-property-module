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

use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\Season;
use Vivutio\Property\Model\SeasonYear;
use Vivutio\Property\Repository\SeasonPeriodRepository;

/**
 * A property's seasons over one year, night by night.
 */
final readonly class SeasonCalendarService
{
    public function __construct(private SeasonPeriodRepository $periods)
    {
    }

    public function year(Property $property, int $year): SeasonYear
    {
        $periods = $this->periods->findByProperty($property);
        $months = [];
        $nights = [];
        $unseasoned = 0;

        for ($month = 1; $month <= 12; ++$month) {
            $first = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));
            $weeks = [];
            $week = array_fill(0, (int) $first->format('N') - 1, null);
            for ($day = $first; (int) $day->format('n') === $month; $day = $day->modify('+1 day')) {
                $season = null;
                foreach ($periods as $period) {
                    if ($period->getStarts() <= $day && $period->getEnds() >= $day) {
                        $season = $period->getSeason();
                        break;
                    }
                }
                if ($season instanceof Season) {
                    $id = (string) $season->getUuid();
                    $nights[$id] = ($nights[$id] ?? 0) + 1;
                } else {
                    ++$unseasoned;
                }
                $week[] = ['date' => $day, 'season' => $season];
                if (7 === \count($week)) {
                    $weeks[] = $week;
                    $week = [];
                }
            }
            if ([] !== $week) {
                $weeks[] = array_pad($week, 7, null);
            }
            $months[] = ['name' => $first->format('F'), 'weeks' => $weeks];
        }

        return new SeasonYear($year, $months, $nights, $unseasoned);
    }
}
