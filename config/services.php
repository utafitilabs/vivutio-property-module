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
use Vivutio\Contracts\Identity\PositionCardFieldInterface;
use Vivutio\Contracts\Place\PlaceSourceInterface;
use Vivutio\Contracts\Place\ReachSourceInterface;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Property\Access\PropertyConcerns;
use Vivutio\Property\Access\PropertyScopes;
use Vivutio\Property\Controller\BookingController;
use Vivutio\Property\Controller\CalendarController;
use Vivutio\Property\Controller\CancellationController;
use Vivutio\Property\Controller\PropertyController;
use Vivutio\Property\Controller\RateController;
use Vivutio\Property\Controller\RoomController;
use Vivutio\Property\Controller\SeasonController;
use Vivutio\Property\Identity\PropertyPositionCard;
use Vivutio\Property\Place\PropertyPlaces;
use Vivutio\Property\Place\PropertyReachSource;
use Vivutio\Property\Repository\ClosureRepository;
use Vivutio\Property\Repository\PropertyBookingLineRepository;
use Vivutio\Property\Repository\PropertyBookingRepository;
use Vivutio\Property\Repository\PropertyReachRepository;
use Vivutio\Property\Repository\PropertyRepository;
use Vivutio\Property\Repository\RateRepository;
use Vivutio\Property\Repository\RateTermsRepository;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Repository\SeasonPeriodRepository;
use Vivutio\Property\Repository\SeasonRepository;
use Vivutio\Property\Service\AvailabilityService;
use Vivutio\Property\Service\CancellationService;
use Vivutio\Property\Service\PropertyBookingService;
use Vivutio\Property\Service\PropertyDirectoryService;
use Vivutio\Property\Service\PropertyGlanceService;
use Vivutio\Property\Service\PropertyService;
use Vivutio\Property\Service\RateQuoteService;
use Vivutio\Property\Service\RateService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonCalendarService;
use Vivutio\Property\Service\SeasonService;
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

    $services->set(SeasonRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(SeasonPeriodRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('property.seasons', SeasonService::class)
        ->args([service('doctrine.orm.entity_manager'), service(SeasonRepository::class), service(SeasonPeriodRepository::class), service(RateRepository::class)]);
    $services->alias(SeasonService::class, 'property.seasons');

    $services->set('property.season_calendar', SeasonCalendarService::class)
        ->args([service(SeasonPeriodRepository::class)]);

    $services->set('property.controller.seasons', SeasonController::class)
        ->args([
            service('twig'),
            service('property.seasons'),
            service(SeasonRepository::class),
            service('property.season_calendar'),
            service('property.directory'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(SeasonController::class, 'property.controller.seasons')->public();

    $services->set(RateRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(RateTermsRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('property.rates', RateService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(RateRepository::class),
            service(RateTermsRepository::class),
            service(RoomTypeRepository::class),
            service(SeasonPeriodRepository::class),
        ]);
    $services->alias(RateService::class, 'property.rates');

    // What a stay costs: the one place a stay is priced, for this tab and for bookings.
    $services->set('property.rate_quotes', RateQuoteService::class)
        ->args([service(RateRepository::class), service(RateTermsRepository::class), service(SeasonPeriodRepository::class)]);
    $services->alias(RateQuoteService::class, 'property.rate_quotes');

    // What cancelling costs, and the tiers it is counted by.
    $services->set('property.cancellation', CancellationService::class)
        ->args([service('doctrine.orm.entity_manager')]);
    $services->alias(CancellationService::class, 'property.cancellation');

    $services->set('property.controller.cancellation', CancellationController::class)
        ->args([
            service('twig'),
            service('property.cancellation'),
            service(SeasonRepository::class),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(CancellationController::class, 'property.controller.cancellation')->public();

    $services->set('property.controller.rates', RateController::class)
        ->args([
            service('twig'),
            service('property.rates'),
            service('property.rate_quotes'),
            service(RateRepository::class),
            service(RateTermsRepository::class),
            service(RoomTypeRepository::class),
            service(SeasonPeriodRepository::class),
            service('property.directory'),
            service('property.cancellation'),
            service(SeasonRepository::class),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(RateController::class, 'property.controller.rates')->public();

    $services->set(ClosureRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // What is free, night by night: the one place it is worked out.
    $services->set('property.availability', AvailabilityService::class)
        ->args([service('doctrine.orm.entity_manager'), service(ClosureRepository::class), service(RoomTypeRepository::class), service(PropertyBookingRepository::class), service('clock')]);
    $services->alias(AvailabilityService::class, 'property.availability');

    $services->set(PropertyBookingRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(PropertyBookingLineRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // Bookings received: recorded, confirmed and cancelled in one place.
    $services->set('property.bookings', PropertyBookingService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service(PropertyBookingRepository::class),
            service(RoomTypeRepository::class),
            service('property.rate_quotes'),
            service('property.availability'),
            service('property.cancellation'),
        ]);
    $services->alias(PropertyBookingService::class, 'property.bookings');

    $services->set('property.controller.bookings', BookingController::class)
        ->args([
            service('twig'),
            service('property.bookings'),
            service(PropertyBookingRepository::class),
            service(RoomTypeRepository::class),
            service('property.directory'),
            service('property.cancellation'),
            service('clock'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(BookingController::class, 'property.controller.bookings')->public();

    $services->set('property.controller.calendar', CalendarController::class)
        ->args([
            service('twig'),
            service('property.availability'),
            service(RoomTypeRepository::class),
            service('property.directory'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(CalendarController::class, 'property.controller.calendar')->public();

    $services->set('property.directory', PropertyDirectoryService::class)
        ->args([service(UserRepository::class), service(DepartmentRepository::class), service(RoomTypeRepository::class), service(PropertyRepository::class), service('security.authorization_checker')]);

    // Where a person's permissions apply among the properties: asked by the
    // core's grant voter, and chosen on the Position card.
    $services->set(PropertyReachRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set('property.reach', PropertyReachSource::class)
        ->args([service(PropertyReachRepository::class), service(UserRepository::class)])
        ->tag(ReachSourceInterface::TAG);
    $services->set('property.position_card', PropertyPositionCard::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(PropertyReachRepository::class),
            service(UserRepository::class),
            service('property.places'),
        ])
        ->tag(PositionCardFieldInterface::TAG);

    // The properties as places to post people at, beside the core's offices.
    $services->set('property.places', PropertyPlaces::class)
        ->args([service(PropertyRepository::class)])
        ->tag(PlaceSourceInterface::TAG);

    // A property at a glance, as of the framework's clock.
    $services->set('property.glance', PropertyGlanceService::class)
        ->args([
            service('clock'),
            service(RateRepository::class),
            service(SeasonPeriodRepository::class),
            service(ClosureRepository::class),
            service('property.cancellation'),
        ]);

    $services->set('property.controller.properties', PropertyController::class)
        ->args([
            service('twig'),
            service('property.properties'),
            service('property.directory'),
            service('property.glance'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(PropertyController::class, 'property.controller.properties')->public();
};
