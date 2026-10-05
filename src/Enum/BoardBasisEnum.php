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
 * What a rate includes beside the bed. A property sells one or more, each at
 * its own rates; a game package is full board with the property's game drives.
 */
enum BoardBasisEnum: string
{
    case RoomOnly = 'room_only';
    case BedAndBreakfast = 'bed_breakfast';
    case HalfBoard = 'half_board';
    case FullBoard = 'full_board';
    case GamePackage = 'game_package';
    case AllInclusive = 'all_inclusive';

    public function label(): string
    {
        return match ($this) {
            self::RoomOnly => 'Room only',
            self::BedAndBreakfast => 'Bed and breakfast',
            self::HalfBoard => 'Half board',
            self::FullBoard => 'Full board',
            self::GamePackage => 'Game package',
            self::AllInclusive => 'All inclusive',
        };
    }
}
