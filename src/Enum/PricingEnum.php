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
 * What a property's rate is the price of: a person sharing a room, with the
 * season's terms for everyone else, or a room, whoever is in it.
 */
enum PricingEnum: string
{
    case PerPerson = 'per_person';
    case PerRoom = 'per_room';

    public function label(): string
    {
        return match ($this) {
            self::PerPerson => 'Per person sharing',
            self::PerRoom => 'Per room',
        };
    }
}
