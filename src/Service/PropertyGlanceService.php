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

use Psr\Clock\ClockInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Model\Glance;
use Vivutio\Property\Repository\ClosureRepository;
use Vivutio\Property\Repository\RateRepository;
use Vivutio\Property\Repository\SeasonPeriodRepository;

/**
 * What a property's tabs hold, in a line each, as of today: from what a night
 * costs (the least of the rates of periods not yet over), the season tonight
 * and the next to start, the closure under way or the next, and the first band
 * of its cancellation tiers.
 */
final readonly class PropertyGlanceService
{
    public function __construct(
        private ClockInterface $clock,
        private RateRepository $rates,
        private SeasonPeriodRepository $periods,
        private ClosureRepository $closures,
        private CancellationService $cancellation,
    ) {
    }

    public function of(Property $property): Glance
    {
        $today = $this->clock->now()->setTime(0, 0);

        $least = null;
        foreach ($this->rates->findByProperty($property) as $rate) {
            if ($rate->getPeriod()->getEnds() >= $today && (null === $least || (float) $rate->getAmount() < (float) $least->getAmount())) {
                $least = $rate;
            }
        }
        $from = null === $least || null === $property->getCurrency()
            ? 'No rates yet'
            : \sprintf('From %s %s a night, %s, %s', $property->getCurrency(), number_format((float) $least->getAmount(), 2), mb_strtolower($property->getPricing()->label()), mb_strtolower($least->getBoard()->label()));

        $tonight = 'In no season';
        $next = 'No season ahead';
        foreach ($this->periods->findByProperty($property) as $period) {
            if ($period->getStarts() <= $today && $period->getEnds() >= $today) {
                $tonight = \sprintf('%s, until %s', $period->getSeason()->getName(), $period->getEnds()->format('j M Y'));
            } elseif ($period->getStarts() > $today && 'No season ahead' === $next) {
                $next = \sprintf('%s, from %s', $period->getSeason()->getName(), $period->getStarts()->format('j M Y'));
            }
        }

        $closure = 'Nothing closed ahead';
        foreach ($this->closures->findByPropertyBetween($property, $today, $today->modify('+5 years')) as $found) {
            $closure = $found->covers($today)
                ? \sprintf('%s, until %s', $found->getReason(), $found->getEnds()->format('j M Y'))
                : \sprintf('%s, %s', $found->getReason(), SeasonService::span($found->getStarts(), $found->getEnds()));
            break;
        }

        return new Glance($from, $tonight, $next, $closure, $this->cancellation->bands($property->getCancellation())[0]);
    }
}
