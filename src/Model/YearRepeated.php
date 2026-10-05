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

/**
 * What repeating a year's periods did: how many started in it, and how many
 * of those were copied a year later rather than left out for overlapping.
 */
final readonly class YearRepeated
{
    public function __construct(
        public int $year,
        public int $found,
        public int $copied,
    ) {
    }

    /** What the page says it did. */
    public function says(): string
    {
        $next = $this->year + 1;

        return match (true) {
            0 === $this->found => \sprintf('%d has no periods to repeat.', $this->year),
            0 === $this->copied => \sprintf('Nothing was repeated: %d has them already, or periods they would overlap.', $next),
            $this->copied === $this->found => \sprintf('%d %s of %d %s repeated in %d.', $this->copied, 1 === $this->copied ? 'period' : 'periods', $this->year, 1 === $this->copied ? 'was' : 'were', $next),
            default => \sprintf('%d of %d periods of %d were repeated in %d; the rest would overlap periods already there.', $this->copied, $this->found, $this->year, $next),
        };
    }
}
