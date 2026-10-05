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

namespace Vivutio\Property\Enum;

/**
 * How busy a season is, as the trade says it. Mid season is the shoulder;
 * a green season is a low one. Its tone on a calendar follows from it, from
 * the quietest to the busiest, so every property's calendar reads alike.
 */
enum SeasonKindEnum: string
{
    case Low = 'low';
    case Shoulder = 'shoulder';
    case High = 'high';
    case Peak = 'peak';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Shoulder => 'Shoulder',
            self::High => 'High',
            self::Peak => 'Peak',
        };
    }

    /** One for the quietest to four for the busiest. */
    public function tone(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Shoulder => 2,
            self::High => 3,
            self::Peak => 4,
        };
    }
}
