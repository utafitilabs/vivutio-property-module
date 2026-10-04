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
 * What a property's guests sleep in, counted: "24 tents", "32 rooms".
 */
enum UnitEnum: string
{
    case Tents = 'tents';
    case Rooms = 'rooms';
    case Cottages = 'cottages';
    case Villas = 'villas';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
