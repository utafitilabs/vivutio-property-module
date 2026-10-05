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
 * Whether a property takes bookings, in one lifecycle: being set up, taking
 * bookings, not taking them for now, or no longer run. Closing for a season
 * is the calendar's business, not this.
 */
enum PropertyStatusEnum: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Archived => 'Archived',
        };
    }

    /** What it means, as the configure page says it beside the choice. */
    public function says(): string
    {
        return match ($this) {
            self::Draft => 'Being set up; nobody can book it yet',
            self::Open => 'Taking bookings',
            self::Closed => 'Not taking new bookings for now; those made stand',
            self::Archived => 'No longer run; kept for its history',
        };
    }

    /**
     * Where it may go from here, itself first: a draft opens, an open
     * property closes, either is archived, and an archived one comes back
     * closed. Nothing goes back to being a draft.
     *
     * @return list<self>
     */
    public function choices(): array
    {
        return match ($this) {
            self::Draft => [self::Draft, self::Open, self::Archived],
            self::Open => [self::Open, self::Closed, self::Archived],
            self::Closed => [self::Closed, self::Open, self::Archived],
            self::Archived => [self::Archived, self::Closed],
        };
    }

    /** Whether people may be posted at it and departments sit at it. */
    public function isAPlace(): bool
    {
        return self::Archived !== $this;
    }
}
