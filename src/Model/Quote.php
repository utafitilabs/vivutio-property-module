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

use Vivutio\Property\Entity\SeasonPeriod;

/**
 * What a stay costs, night by night, in cents of its property's currency.
 */
final readonly class Quote
{
    /**
     * @param list<array{date: \DateTimeImmutable, season: string, period: SeasonPeriod, lines: array<string, int>, total: int}> $nights
     */
    public function __construct(
        public string $currency,
        public array $nights,
        public int $total,
    ) {
    }

    /**
     * @return array<string, int> each night's cost, by its date
     */
    public function nightly(): array
    {
        $nightly = [];
        foreach ($this->nights as $night) {
            $nightly[$night['date']->format('Y-m-d')] = $night['total'];
        }

        return $nightly;
    }

    /** An amount as the page says it: "USD 1,580.00". */
    public function says(int $cents): string
    {
        return $this->currency.' '.number_format($cents / 100, 2);
    }
}
