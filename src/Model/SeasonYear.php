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

namespace Vivutio\Property\Model;

use Vivutio\Property\Entity\Season;

/**
 * A year of a property's seasons as its calendar draws it: each month a grid
 * of weeks from Monday, each night in its season or in none, and how many
 * nights of the year each season holds.
 */
final readonly class SeasonYear
{
    /**
     * @param list<array{name: string, weeks: list<list<array{date: \DateTimeImmutable, season: ?Season}|null>>}> $months
     * @param array<string, int>                                                                                  $nights by season uuid
     */
    public function __construct(
        public int $year,
        public array $months,
        public array $nights,
        public int $unseasoned,
    ) {
    }
}
