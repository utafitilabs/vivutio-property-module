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
 * What cancelling a stay costs on a day: by season, the share charged of what
 * its nights cost and the charge, in cents of the property's currency.
 */
final readonly class CancellationCharge
{
    /**
     * @param array<string, array{int, int, int}> $bySeason   by season name: the share in per cent, what its nights cost, the charge
     * @param int|null                            $freeBefore the most days before arrival any tier counts, or null where none does
     */
    public function __construct(
        public string $currency,
        public int $days,
        public array $bySeason,
        public int $total,
        public ?int $freeBefore,
    ) {
    }

    /** When it is cancelled, as the page says it. */
    public function when(): string
    {
        return match (true) {
            0 === $this->days => 'on the night of arrival',
            $this->days < 0 => \sprintf('%d %s after arrival', -$this->days, -1 === $this->days ? 'day' : 'days'),
            null !== $this->freeBefore && $this->days > $this->freeBefore => \sprintf('%d days before arrival, more than %d days, so it is free', $this->days, $this->freeBefore),
            default => \sprintf('%d %s before arrival', $this->days, 1 === $this->days ? 'day' : 'days'),
        };
    }

    /** An amount as the page says it: "USD 232.00". */
    public function says(int $cents): string
    {
        return $this->currency.' '.number_format($cents / 100, 2);
    }
}
