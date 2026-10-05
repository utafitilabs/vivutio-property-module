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
 * Where a person's permissions apply, as the Position card offers it.
 */
enum ReachEnum: string
{
    case Organization = 'organization';
    case Posted = 'posted';
    case Chosen = 'chosen';

    public function label(): string
    {
        return match ($this) {
            self::Organization => 'Whole organization',
            self::Posted => 'Where posted',
            self::Chosen => 'Chosen properties',
        };
    }
}
