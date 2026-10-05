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

namespace Vivutio\Property\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Property\Entity\Closure;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Exception\InvalidClosureException;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Service\AvailabilityService;
use Vivutio\Property\Service\PropertyDirectoryService;

/**
 * A property's Calendar tab, a month at a time, a night's own page, and its
 * closures. Read with properties.read; closures are added and removed by the
 * tiers alone.
 */
final readonly class CalendarController
{
    public const string CALENDAR = 'property_calendar';
    public const string NIGHT = 'property_calendar_night';
    public const string CLOSE = 'property_closure_add';
    public const string REMOVE = 'property_closure_remove';

    public function __construct(
        private Environment $twig,
        private AvailabilityService $availability,
        private RoomTypeRepository $rooms,
        private PropertyDirectoryService $directory,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties/{uuid}/calendar', name: self::CALENDAR, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(PropertyController::READ)]
    public function calendar(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        return $this->page($property, self::month($request->query->getString('month')));
    }

    #[Route('/properties/{uuid}/calendar/{night}', name: self::NIGHT, requirements: ['uuid' => Requirement::UUID, 'night' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    #[IsGranted(PropertyController::READ)]
    public function night(
        string $night,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $night);
        if (false === $date || $date->format('Y-m-d') !== $night) {
            throw new NotFoundHttpException();
        }

        return new Response($this->twig->render('@VivutioProperty/properties/calendar_night.html.twig', [
            'property' => $property,
            'band' => $this->directory->band($property),
            'night' => $this->availability->night($property, $date),
        ]));
    }

    #[Route('/properties/{uuid}/closures', name: self::CLOSE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE)]
    public function close(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $payload = $request->getPayload();
        $typed = [];
        foreach (['room', 'units', 'starts', 'ends', 'reason'] as $field) {
            $typed[$field] = $payload->getString($field);
        }
        $month = self::month(substr($typed['starts'], 0, 7));
        if (!$this->tokens->isTokenValid(new CsrfToken('property_closure_add', $payload->getString('_token')))) {
            return $this->page($property, $month, $typed, expired: true);
        }

        try {
            $closure = $this->availability->close($property, $typed['room'], $typed['units'], $typed['starts'], $typed['ends'], $typed['reason']);
        } catch (InvalidClosureException $refusal) {
            return $this->page($property, $month, $typed, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($property, $closure->getStarts());
    }

    #[Route('/properties/{uuid}/closures/{closure}/remove', name: self::REMOVE, requirements: ['uuid' => Requirement::UUID, 'closure' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE)]
    public function remove(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['closure' => 'uuid'])]
        Closure $closure,
    ): Response {
        if ($closure->getProperty() !== $property) {
            throw new NotFoundHttpException();
        }
        if (!$this->tokens->isTokenValid(new CsrfToken('property_closure_remove', $request->getPayload()->getString('_token')))) {
            return $this->page($property, $closure->getStarts()->modify('first day of this month'), expired: true);
        }

        $this->availability->remove($closure);

        return $this->to($property, $closure->getStarts());
    }

    /** The first of the month asked for, "2026-07", or of this month. */
    private static function month(string $typed): \DateTimeImmutable
    {
        $month = 1 === preg_match('{^(20\d\d|2100)-(0[1-9]|1[0-2])$}D', $typed) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $typed.'-01') : false;

        return false === $month ? new \DateTimeImmutable('first day of this month midnight') : $month;
    }

    private function to(Property $property, \DateTimeImmutable $night): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::CALENDAR, ['uuid' => $property->getUuid(), 'month' => $night->format('Y-m')]));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function page(Property $property, \DateTimeImmutable $month, array $typed = [], array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioProperty/properties/calendar.html.twig', [
            'property' => $property,
            'band' => $this->directory->band($property),
            'month' => $this->availability->month($property, (int) $month->format('Y'), (int) $month->format('n')),
            'rooms' => $this->rooms->findOnSaleByProperty($property),
            'typed' => [...['room' => '', 'units' => '', 'starts' => $month->format('Y-m-d'), 'ends' => $month->format('Y-m-d'), 'reason' => ''], ...$typed],
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
