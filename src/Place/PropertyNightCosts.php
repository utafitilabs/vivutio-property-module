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

use Vivutio\Contracts\Stay\NightCost;
use Vivutio\Contracts\Stay\NightCostSourceInterface;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Exception\QuoteRefusedException;
use Vivutio\Property\Repository\PropertyRepository;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Service\RateQuoteService;

/**
 * What a night at a property costs a person on a tour: two adults sharing its
 * cheapest room for two on sale that night, on full board where it offers
 * full board, else on the first board it offers; from its rate card, so what a
 * tour costs moves with the property's rates.
 */
final readonly class PropertyNightCosts implements NightCostSourceInterface
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomTypeRepository $rooms,
        private RateQuoteService $quotes,
    ) {
    }

    public function kind(): string
    {
        return Property::PLACE_KIND;
    }

    public function cost(string $id, \DateTimeImmutable $night): ?NightCost
    {
        $property = $this->properties->findOneBy(['uuid' => $id]);
        $boards = $property?->getBoards() ?? [];
        if (null === $property || [] === $boards) {
            return null;
        }
        $board = \in_array(BoardBasisEnum::FullBoard->value, $boards, true) ? BoardBasisEnum::FullBoard : BoardBasisEnum::from($boards[0]);

        $cheapest = null;
        foreach ($this->rooms->findByProperty($property) as $room) {
            if ($room->isWithdrawn() || $room->getAdults() < 2) {
                continue;
            }
            try {
                $quote = $this->quotes->quote($room, $board, $night, 1, 2, 0, 0);
            } catch (QuoteRefusedException) {
                continue;
            }
            $each = intdiv($quote->total, 2);
            if (null === $cheapest || $each < $cheapest->each) {
                $cheapest = new NightCost($quote->currency, $each, \sprintf('%s, %s, sharing', $room->getName(), mb_strtolower($board->label())));
            }
        }

        return $cheapest;
    }
}
