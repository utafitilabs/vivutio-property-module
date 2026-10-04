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

namespace Vivutio\Property\Access;

use Vivutio\Contracts\Access\Concern;
use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Access\Scope;
use Vivutio\Contracts\Access\Verb;

/**
 * What a position may grant about properties. A module's pair, so a person
 * reaches it only where their department allows it too.
 */
final readonly class PropertyConcerns implements ConcernSourceInterface
{
    public const string PROPERTIES = 'properties';

    public function declaredBy(): string
    {
        return 'Properties';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::PROPERTIES,
            label: 'Properties',
            description: 'The organization\'s camps, lodges and hotels: where each is and what its guests sleep in.',
            verbs: [Verb::Read, Verb::Configure],
            scopes: [Scope::ORGANIZATION, PropertyScopes::PROPERTY],
            moduleSlug: 'property',
            // The organization's structure is set by the tiers alone.
            tierOnly: [Verb::Configure],
        );
    }
}
