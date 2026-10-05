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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vivutio\Bundle\IdentityBundle\Repository\DepartmentRepository;
use Vivutio\Bundle\IdentityBundle\Repository\UserRepository;
use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Access\ScopeSourceInterface;
use Vivutio\Contracts\Place\PlaceSourceInterface;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Property\Access\PropertyConcerns;
use Vivutio\Property\Access\PropertyScopes;
use Vivutio\Property\Controller\PropertyController;
use Vivutio\Property\Controller\RoomController;
use Vivutio\Property\Place\PropertyPlaces;
use Vivutio\Property\Repository\PropertyRepository;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Service\PropertyDirectoryService;
use Vivutio\Property\Service\PropertyService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Shell\PropertyMenu;

/*
 * Every service is defined explicitly, with an id prefixed by the bundle's
 * alias; nothing is autowired or autoconfigured, so a tag is applied by hand.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html#services
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // What a position may grant about properties, and the scope a grant may be limited to.
    $services->set('property.access.concerns', PropertyConcerns::class)
        ->tag(ConcernSourceInterface::TAG);
    $services->set('property.access.scopes', PropertyScopes::class)
        ->tag(ScopeSourceInterface::TAG);

    // The module's page in the menu, through the shell's seam.
    $services->set('property.menu', PropertyMenu::class)
        ->tag(MenuSourceInterface::TAG);

    $services->set(PropertyRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('property.properties', PropertyService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(PropertyRepository::class),
            service(UserRepository::class),
            service(DepartmentRepository::class),
            service(RoomTypeRepository::class),
        ]);
    $services->alias(PropertyService::class, 'property.properties');

    $services->set(RoomTypeRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('property.room_types', RoomTypeService::class)
        ->args([service('doctrine.orm.entity_manager'), service(RoomTypeRepository::class)]);
    $services->alias(RoomTypeService::class, 'property.room_types');

    $services->set('property.controller.rooms', RoomController::class)
        ->args([
            service('twig'),
            service('property.room_types'),
            service(RoomTypeRepository::class),
            service('property.directory'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(RoomController::class, 'property.controller.rooms')->public();

    $services->set('property.directory', PropertyDirectoryService::class)
        ->args([service(UserRepository::class), service(DepartmentRepository::class), service(RoomTypeRepository::class)]);

    // The properties as places to post people at, beside the core's offices.
    $services->set('property.places', PropertyPlaces::class)
        ->args([service(PropertyRepository::class)])
        ->tag(PlaceSourceInterface::TAG);

    $services->set('property.controller.properties', PropertyController::class)
        ->args([
            service('twig'),
            service('property.properties'),
            service('property.directory'),
            service(PropertyRepository::class),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(PropertyController::class, 'property.controller.properties')->public();
};
