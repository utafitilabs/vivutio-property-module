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
 * What is in a room or a tent. A feature of the property itself, a pool, is
 * an amenity, and is not this.
 */
enum RoomFeatureEnum: string
{
    case EnSuite = 'en_suite';
    case OutdoorShower = 'outdoor_shower';
    case MosquitoNet = 'mosquito_net';
    case Fan = 'fan';
    case AirConditioning = 'air_conditioning';
    case Fireplace = 'fireplace';
    case PrivateDeck = 'private_deck';
    case PrivatePool = 'private_pool';
    case TeaAndCoffee = 'tea_coffee';
    case MiniBar = 'mini_bar';
    case Safe = 'safe';
    case Wifi = 'wifi';

    public function label(): string
    {
        return match ($this) {
            self::EnSuite => 'En-suite bathroom',
            self::OutdoorShower => 'Outdoor shower',
            self::MosquitoNet => 'Mosquito net',
            self::Fan => 'Fan',
            self::AirConditioning => 'Air conditioning',
            self::Fireplace => 'Fireplace',
            self::PrivateDeck => 'Private deck or balcony',
            self::PrivatePool => 'Private pool',
            self::TeaAndCoffee => 'Tea and coffee',
            self::MiniBar => 'Mini bar',
            self::Safe => 'Safe',
            self::Wifi => 'Wi-Fi',
        };
    }
}
