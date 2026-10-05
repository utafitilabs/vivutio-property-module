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
 * What kind of establishment a property is, as the trade names it. A mobile
 * camp moves with the season; a tented lodge has a lodge's main building and
 * tents to sleep in.
 */
enum PropertyTypeEnum: string
{
    case Lodge = 'lodge';
    case TentedLodge = 'tented_lodge';
    case TentedCamp = 'tented_camp';
    case MobileCamp = 'mobile_camp';
    case Camp = 'camp';
    case Hotel = 'hotel';
    case Resort = 'resort';
    case Guesthouse = 'guesthouse';
    case Villa = 'villa';

    public function label(): string
    {
        return match ($this) {
            self::Lodge => 'Lodge',
            self::TentedLodge => 'Tented lodge',
            self::TentedCamp => 'Tented camp',
            self::MobileCamp => 'Mobile camp',
            self::Camp => 'Camp',
            self::Hotel => 'Hotel',
            self::Resort => 'Resort',
            self::Guesthouse => 'Guesthouse',
            self::Villa => 'Villa',
        };
    }
}
