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

use Vivutio\Property\Entity\Property;

/**
 * The register as asked for: the properties that match, and every count over
 * all of them, so a chip says what choosing it would show.
 */
final readonly class PropertyRegister
{
    /**
     * @param list<Property>        $properties   those that match, by name
     * @param array<string, int>    $statusCounts by status
     * @param array<string, int>    $typeCounts   by type, those that occur
     * @param array<string, string> $query        the filters in force, by name, as the address carries them
     */
    public function __construct(
        public array $properties,
        public int $total,
        public array $statusCounts,
        public array $typeCounts,
        public array $query,
    ) {
    }
}
