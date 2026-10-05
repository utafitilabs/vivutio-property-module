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
use Vivutio\Property\Exception\InvalidCancellationException;
use Vivutio\Property\Model\CancellationCharge;
use Vivutio\Property\Model\Quote;

/**
 * What cancelling costs. A property has tiers, each "from so many days before
 * arrival, so much of what the stay costs"; earlier than its first tier is
 * free, and on or after arrival its last applies. A season follows its
 * property's tiers, has its own, or charges nothing. A stay across seasons is
 * charged night by night, each night by its own season's tiers, the days
 * counted to the arrival.
 */
final readonly class CancellationService
{
    public const int MOST_TIERS = 6;
    public const int MOST_DAYS = 365;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<array{string, string}> $tiers days before arrival, and the share charged
     *
     * @throws InvalidCancellationException
     */
    public function changeProperty(Property $property, array $tiers): void
    {
        $property->setCancellation(self::tiers($tiers));
        $this->entityManager->flush();
    }

    /**
     * Follow its property's tiers ("follow"), charge nothing ("no_charge"), or
     * have its own ("own").
     *
     * @param list<array{string, string}> $tiers
     *
     * @throws InvalidCancellationException
     */
    public function changeSeason(Season $season, string $mode, array $tiers): void
    {
        $season->setCancellation(match ($mode) {
            'follow' => null,
            'no_charge' => [],
            'own' => [] === ($own = self::tiers($tiers)) ? throw new InvalidCancellationException('tiers', 'Give its own tiers, or choose no charge.') : $own,
            default => throw new InvalidCancellationException('mode', 'Choose whether it follows the property, charges nothing, or has its own tiers.'),
        });
        $this->entityManager->flush();
    }

    /**
     * The tiers a season's nights are charged by.
     *
     * @return list<array{days: int, percent: int}>
     */
    public function tiersOf(Season $season): array
    {
        return $season->getCancellation() ?? $season->getProperty()->getCancellation();
    }

    /**
     * The tiers said as bands, each naming its own days: "20% from 60 to 45
     * days before".
     *
     * @param list<array{days: int, percent: int}> $tiers
     *
     * @return list<string>
     */
    public function bands(array $tiers): array
    {
        if ([] === $tiers) {
            return ['No charge to cancel'];
        }

        $bands = [\sprintf('Free more than %d days before arrival', $tiers[0]['days'])];
        foreach ($tiers as $i => $tier) {
            $next = $tiers[$i + 1] ?? null;
            $bands[] = null === $next
                ? (0 === $tier['days'] ? \sprintf('%d%% on the night of arrival, and after', $tier['percent']) : \sprintf('%d%% from %d days before, and after arrival', $tier['percent'], $tier['days']))
                : \sprintf('%d%% from %d to %d days before', $tier['percent'], $tier['days'], $next['days'] + 1);
        }

        return $bands;
    }

    public function charge(Quote $stay, \DateTimeImmutable $cancelledOn): CancellationCharge
    {
        $arrival = $stay->nights[0]['date'];
        $days = (int) $cancelledOn->setTime(0, 0)->diff($arrival)->format('%r%a');

        $bySeason = [];
        $freeBefore = null;
        foreach ($stay->nights as $night) {
            $tiers = $this->tiersOf($night['period']->getSeason());
            if ([] !== $tiers) {
                $freeBefore = max($freeBefore ?? 0, $tiers[0]['days']);
            }
            $percent = self::percent($tiers, $days);
            [, $of, $charged] = $bySeason[$night['season']] ?? [$percent, 0, 0];
            $bySeason[$night['season']] = [$percent, $of + $night['total'], $charged + intdiv($night['total'] * $percent + 50, 100)];
        }

        return new CancellationCharge($stay->currency, $days, $bySeason, array_sum(array_map(static fn (array $line): int => $line[2], $bySeason)), $freeBefore);
    }

    /**
     * @param list<array{days: int, percent: int}> $tiers
     */
    private static function percent(array $tiers, int $days): int
    {
        if ([] === $tiers) {
            return 0;
        }
        if ($days <= 0) {
            return $tiers[\count($tiers) - 1]['percent'];
        }

        $charged = 0;
        foreach ($tiers as $tier) {
            if ($days <= $tier['days']) {
                $charged = $tier['percent'];
            }
        }

        return $charged;
    }

    /**
     * Typed rows as kept: whole days and whole per cent, the most days first,
     * each count of days once, and never less charged for cancelling later.
     * A row left empty is left out.
     *
     * @param list<array{string, string}> $rows
     *
     * @return list<array{days: int, percent: int}>
     *
     * @throws InvalidCancellationException
     */
    private static function tiers(array $rows): array
    {
        $tiers = [];
        foreach ($rows as [$days, $percent]) {
            $days = trim($days);
            $percent = trim($percent);
            if ('' === $days && '' === $percent) {
                continue;
            }
            if (!ctype_digit($days) || (int) $days > self::MOST_DAYS) {
                throw new InvalidCancellationException('tiers', \sprintf('Days before arrival are a whole number of days, from 0 to %d.', self::MOST_DAYS));
            }
            if (!ctype_digit($percent) || (int) $percent < 1 || (int) $percent > 100) {
                throw new InvalidCancellationException('tiers', 'What is charged is a share of the stay, from 1 to 100 per cent.');
            }
            foreach ($tiers as $tier) {
                if ($tier['days'] === (int) $days) {
                    throw new InvalidCancellationException('tiers', \sprintf('%d days before arrival is given twice: each count of days once.', (int) $days));
                }
            }
            $tiers[] = ['days' => (int) $days, 'percent' => (int) $percent];
        }
        if (\count($tiers) > self::MOST_TIERS) {
            throw new InvalidCancellationException('tiers', \sprintf('At most %d tiers.', self::MOST_TIERS));
        }

        usort($tiers, static fn (array $a, array $b): int => $b['days'] <=> $a['days']);
        foreach ($tiers as $i => $tier) {
            $earlier = $tiers[$i - 1] ?? null;
            if (null !== $earlier && $tier['percent'] < $earlier['percent']) {
                throw new InvalidCancellationException('tiers', \sprintf('Cancelling %d days before charges less (%d%%) than %d days before (%d%%): a later cancellation is never charged less.', $tier['days'], $tier['percent'], $earlier['days'], $earlier['percent']));
            }
        }

        return $tiers;
    }
}
