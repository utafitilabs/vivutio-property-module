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

/**
 * A booking as the form sends it, every value as typed; the service reads and
 * refuses it field by field.
 */
final readonly class BookingDetails
{
    /**
     * @param list<array{room: string, rooms: string, adults: string, children: string, infants: string, board: string}> $lines
     */
    public function __construct(
        public string $guest,
        public string $bookedBy,
        public string $theirReference,
        public string $arrival,
        public string $nights,
        public string $status,
        public string $heldUntil,
        public string $notes,
        public array $lines,
    ) {
    }

    /**
     * @param array<mixed> $sent
     */
    public static function fromForm(array $sent): self
    {
        $text = static fn (array $from, string $key): string => \is_string($from[$key] ?? null) ? $from[$key] : '';
        $lines = [];
        foreach (\is_array($sent['lines'] ?? null) ? $sent['lines'] : [] as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $lines[] = ['room' => $text($line, 'room'), 'rooms' => $text($line, 'rooms'), 'adults' => $text($line, 'adults'), 'children' => $text($line, 'children'), 'infants' => $text($line, 'infants'), 'board' => $text($line, 'board')];
        }

        return new self(
            $text($sent, 'guest'),
            $text($sent, 'booked_by'),
            $text($sent, 'their_reference'),
            $text($sent, 'arrival'),
            $text($sent, 'nights'),
            $text($sent, 'status'),
            $text($sent, 'held_until'),
            $text($sent, 'notes'),
            $lines,
        );
    }
}
