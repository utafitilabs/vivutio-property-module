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
 * What carrying a year's rates into the next did: of the periods that start
 * in the year, how many had their season's period a year later; how many
 * rates were copied there, and how many were left because one was set.
 */
final readonly class RatesCarried
{
    public function __construct(
        public int $year,
        public int $raise,
        public int $periods,
        public int $unmatched,
        public int $copied,
        public int $left,
    ) {
    }

    /** What the page says it did. */
    public function says(): string
    {
        $next = $this->year + 1;
        if (0 === $this->periods) {
            return \sprintf('%d has no season periods to carry rates from.', $this->year);
        }
        if ($this->unmatched === $this->periods) {
            return \sprintf('%d has none of the periods of %d: repeat %d on the Seasons tab first.', $next, $this->year, $this->year);
        }
        if (0 === $this->copied) {
            return $this->left > 0 ? \sprintf('Nothing was carried: %d has them already.', $next) : \sprintf('%d has no rates to carry.', $this->year);
        }

        $said = \sprintf('%d %s of %d %s carried into %d%s.', $this->copied, 1 === $this->copied ? 'rate' : 'rates', $this->year, 1 === $this->copied ? 'was' : 'were', $next, $this->raise > 0 ? \sprintf(', raised by %d%%', $this->raise) : '');
        if ($this->left > 0) {
            $said .= \sprintf(' %d already set %s left as %s.', $this->left, 1 === $this->left ? 'was' : 'were', 1 === $this->left ? 'it was' : 'they were');
        }
        if ($this->unmatched > 0) {
            $said .= \sprintf(' %d %s no period a year later.', $this->unmatched, 1 === $this->unmatched ? 'period had' : 'periods had');
        }

        return $said;
    }
}
