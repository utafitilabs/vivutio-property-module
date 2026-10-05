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
 * A property at a glance, each line what one of its tabs holds, as said.
 */
final readonly class Glance
{
    public function __construct(
        public string $from,
        public string $tonight,
        public string $next,
        public string $closure,
        public string $cancellation,
    ) {
    }
}
