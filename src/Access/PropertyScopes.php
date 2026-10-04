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

use Vivutio\Contracts\Access\Scope;
use Vivutio\Contracts\Access\ScopeSourceInterface;

/**
 * The scope this module owns: a grant limited to named properties.
 */
final readonly class PropertyScopes implements ScopeSourceInterface
{
    public const string PROPERTY = 'property';

    public function declaredBy(): string
    {
        return 'Properties';
    }

    public function scopes(): iterable
    {
        yield new Scope(self::PROPERTY, 'Property', 'One or more named properties.');
    }
}
