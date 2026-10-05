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

use Vivutio\Property\Entity\Closure;
use Vivutio\Property\Entity\RoomType;

/**
 * A month of a property's availability: each room type on sale, night by
 * night, with how many are free and whether it is closed, and the closures
 * that touch the month.
 */
final readonly class AvailabilityMonth
{
    /**
     * @param list<\DateTimeImmutable>                                                                            $nights
     * @param list<array{room: RoomType, nights: list<array{date: \DateTimeImmutable, free: int, closed: bool}>}> $rows
     * @param list<Closure>                                                                                       $closures
     */
    public function __construct(
        public \DateTimeImmutable $month,
        public array $nights,
        public array $rows,
        public array $closures,
    ) {
    }
}
