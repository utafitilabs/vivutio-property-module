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

namespace Vivutio\Property\Exception;

/**
 * A stay that cannot be priced, with the reason: a night in no season, a
 * rate or a term not set, a party the room does not take.
 */
final class QuoteRefusedException extends \DomainException
{
}
