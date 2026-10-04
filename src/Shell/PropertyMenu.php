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

namespace Vivutio\Property\Shell;

use Vivutio\Contracts\Shell\MenuEntry;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Property\Controller\PropertyController;

/**
 * The module's page in the menu, with the icon the approved components page
 * draws for it.
 */
final readonly class PropertyMenu implements MenuSourceInterface
{
    public function entries(): iterable
    {
        yield new MenuEntry(
            PropertyController::REGISTER,
            'Properties',
            PropertyController::READ,
            '<path d="M3.5 21 14 3"/><path d="M20.5 21 10 3"/><path d="M15.5 21 12 15l-3.5 6"/><path d="M2 21h20"/><path d="M8 2v3"/>',
            'properties',
        );
    }
}
