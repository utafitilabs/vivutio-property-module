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

namespace Vivutio\Property\Place;

use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Place\PlaceSourceInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Repository\PropertyRepository;

/**
 * The properties as places to post people at, offered beside the core's
 * offices: every property but an archived one.
 */
final readonly class PropertyPlaces implements PlaceSourceInterface
{
    public function __construct(private PropertyRepository $properties)
    {
    }

    public function kind(): string
    {
        return Property::PLACE_KIND;
    }

    public function label(): string
    {
        return 'Properties';
    }

    public function places(): iterable
    {
        return $this->properties->findBy(['status' => array_values(array_filter(PropertyStatusEnum::cases(), static fn (PropertyStatusEnum $status): bool => $status->isAPlace()))], ['name' => 'ASC']);
    }

    public function find(string $id): ?PlaceInterface
    {
        return $this->properties->findOneBy(['uuid' => $id]);
    }
}
